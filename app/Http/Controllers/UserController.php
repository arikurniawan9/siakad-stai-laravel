<?php

namespace App\Http\Controllers;

use App\Domain\Security\UserAdministrationService;
use App\Domain\Security\UserTransferService;
use App\Http\Requests\UserBulkRequest;
use App\Http\Requests\UserRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Role;

final class UserController extends Controller
{
    public function index(Request $request, UserTransferService $transferService): Response
    {
        Gate::authorize('viewAny', User::class);
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'role' => ['nullable', 'string', 'exists:roles,name'],
            'state' => ['nullable', Rule::in(['active', 'inactive'])],
            'archive' => ['nullable', Rule::in(['available', 'archived'])],
            'selected' => ['nullable', 'integer'],
            'import' => ['nullable', 'uuid'],
        ]);
        $search = trim((string) ($filters['q'] ?? ''));
        $role = $filters['role'] ?? '';
        $state = $filters['state'] ?? '';
        $archive = $filters['archive'] ?? 'available';

        $base = User::query()
            ->when($archive === 'archived', fn (Builder $query) => $query->onlyTrashed())
            ->when($search !== '', fn (Builder $query) => $query->where(fn (Builder $query) => $query
                ->where('name', 'like', "%{$search}%")
                ->orWhere('username', 'like', "%{$search}%")
                ->orWhere('email', 'like', "%{$search}%")))
            ->when($role !== '', fn (Builder $query) => $query->role($role))
            ->when($state === 'active', fn (Builder $query) => $query->where('is_active', true))
            ->when($state === 'inactive', fn (Builder $query) => $query->where('is_active', false));

        $selectedId = isset($filters['selected']) ? (clone $base)->whereKey($filters['selected'])->value('id') : (clone $base)->orderBy('name')->value('id');
        $selected = $selectedId ? User::withTrashed()->with('roles:id,name')->find($selectedId) : null;
        $importToken = $filters['import'] ?? null;
        $storedPreview = $importToken ? $request->session()->get("user_imports.{$importToken}") : null;
        $importPreview = is_array($storedPreview) && ($storedPreview['user_id'] ?? null) === $request->user()->id
            ? $transferService->present($storedPreview, $importToken)
            : null;

        return Inertia::render('Admin/Users', [
            'filters' => ['q' => $search, 'role' => $role, 'state' => $state, 'archive' => $archive, 'selected' => $selectedId],
            'users' => (clone $base)->with('roles:id,name')->orderBy('name')->paginate(12)->withQueryString(),
            'selectedUser' => $selected,
            'roleOptions' => Role::query()->where('guard_name', 'web')->orderBy('name')->pluck('name'),
            'summary' => [
                'total' => User::query()->count(),
                'active' => User::query()->where('is_active', true)->count(),
                'multiRole' => User::query()->has('roles', '>', 1)->count(),
                'archived' => User::onlyTrashed()->count(),
            ],
            'abilities' => [
                'create' => $request->user()->can('create', User::class),
                'update' => $request->user()->can('update', new User),
                'delete' => $request->user()->can('delete', new User),
            ],
            'transferAbility' => [
                'import' => $request->user()->can('users.update'),
                'export' => $request->user()->can('users.export'),
            ],
            'importPreview' => $importPreview,
            'currentUserId' => $request->user()->id,
        ]);
    }

    public function store(UserRequest $request): RedirectResponse
    {
        Gate::authorize('create', User::class);
        $user = DB::transaction(function () use ($request): User {
            $data = $request->validated();
            $roles = $data['roles'];
            unset($data['roles'], $data['password_confirmation']);
            $user = User::create($data);
            $user->syncRoles($roles);
            $this->audit($request, 'created', $user->id, null, $this->snapshot($user));

            return $user;
        });

        return to_route('admin.users', ['selected' => $user->id])->with('success', 'Akun pengguna berhasil ditambahkan.');
    }

    public function update(UserRequest $request, User $user, UserAdministrationService $service): RedirectResponse
    {
        Gate::authorize('update', $user);
        DB::transaction(function () use ($request, $user, $service): void {
            $old = $this->snapshot($user);
            $updated = $service->update($user, $request->validated(), $request->user());
            $this->audit($request, 'updated', $updated->id, $old, $this->snapshot($updated));
        }, 3);

        return back()->with('success', 'Akun pengguna berhasil diperbarui.');
    }

    public function destroy(Request $request, User $user, UserAdministrationService $service): RedirectResponse
    {
        Gate::authorize('delete', $user);
        DB::transaction(function () use ($request, $user, $service): void {
            $old = $this->snapshot($user);
            $archived = $service->archive($user, $request->user());
            $this->audit($request, 'archived', $archived->id, $old, null);
        }, 3);

        return to_route('admin.users')->with('success', 'Akun dinonaktifkan dan dipindahkan ke arsip.');
    }

    public function restore(Request $request, int $user): RedirectResponse
    {
        $model = User::onlyTrashed()->findOrFail($user);
        Gate::authorize('restore', $model);
        DB::transaction(function () use ($request, $model): void {
            $model->restore();
            $model->forceFill(['is_active' => false])->save();
            $this->audit($request, 'restored', $model->id, null, $this->snapshot($model));
        });

        return to_route('admin.users', ['selected' => $model->id])->with('success', 'Akun dipulihkan dalam keadaan nonaktif.');
    }

    public function bulk(UserBulkRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $ids = $data['ids'];
        $action = $data['action'];

        DB::transaction(function () use ($request, $ids, $action): void {
            $query = $action === 'restore' ? User::onlyTrashed() : User::query();
            $users = $query->whereIn('id', $ids)->with('roles:id,name')->lockForUpdate()->get();
            if ($users->count() !== count($ids)) throw ValidationException::withMessages(['bulk' => 'Sebagian akun tidak ditemukan atau status arsipnya sudah berubah. Muat ulang halaman.']);

            if (in_array($action, ['deactivate', 'archive'], true) && $users->contains(fn (User $user): bool => $user->is($request->user()))) {
                throw ValidationException::withMessages(['bulk' => 'Akun yang sedang digunakan tidak dapat dinonaktifkan atau diarsipkan.']);
            }

            $activeAdminIds = User::query()
                ->where('is_active', true)
                ->whereHas('roles', fn ($query) => $query->where('name', 'Admin'))
                ->lockForUpdate()
                ->pluck('users.id')
                ->mapWithKeys(fn ($id): array => [(int) $id => true])
                ->all();
            $removedActiveAdmin = false;
            foreach ($users as $user) {
                if (in_array($action, ['deactivate', 'archive'], true) && isset($activeAdminIds[$user->id])) {
                    unset($activeAdminIds[$user->id]);
                    $removedActiveAdmin = true;
                }
                if ($action === 'activate' && $user->hasRole('Admin')) $activeAdminIds[$user->id] = true;
            }
            if ($removedActiveAdmin && $activeAdminIds === []) throw ValidationException::withMessages(['bulk' => 'Aksi dibatalkan karena sistem harus tetap memiliki minimal satu Admin aktif.']);

            foreach ($users as $user) {
                $old = $this->snapshot($user);
                if ($action === 'restore') {
                    $user->restore();
                    $user->forceFill(['is_active' => false])->save();
                } elseif ($action === 'archive') {
                    $user->forceFill(['is_active' => false])->save();
                    $user->delete();
                } else {
                    $user->forceFill(['is_active' => $action === 'activate'])->save();
                }
                $auditAction = match ($action) {
                    'activate' => 'activated',
                    'deactivate' => 'deactivated',
                    'archive' => 'archived',
                    default => 'restored',
                };
                $this->audit($request, $auditAction, $user->id, $old, $action === 'archive' ? null : $this->snapshot($user->fresh(['roles'])));
            }
        }, 3);

        $label = match ($action) {
            'activate' => 'diaktifkan',
            'deactivate' => 'dinonaktifkan',
            'archive' => 'diarsipkan',
            default => 'dipulihkan dalam keadaan nonaktif',
        };

        return back()->with('success', count($ids)." akun berhasil {$label}.");
    }

    private function snapshot(User $user): array
    {
        $attributes = $user->getAttributes();
        unset($attributes['password'], $attributes['remember_token']);
        $attributes['roles'] = $user->roles()->orderBy('name')->pluck('name')->all();

        return $attributes;
    }

    private function audit(Request $request, string $action, int $id, ?array $old, ?array $new): void
    {
        DB::table('audit_logs')->insert([
            'user_id' => $request->user()->id,
            'module' => 'users',
            'action' => $action,
            'record_type' => 'user',
            'record_id' => (string) $id,
            'old_data' => $old ? json_encode($old) : null,
            'new_data' => $new ? json_encode($new) : null,
            'ip_address' => $request->ip(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
