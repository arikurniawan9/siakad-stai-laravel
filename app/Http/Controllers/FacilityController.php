<?php

namespace App\Http\Controllers;

use App\Domain\MasterData\MasterDataTransferService;
use App\Http\Requests\FacilityBulkRequest;
use App\Http\Requests\FacilityRequest;
use App\Models\Building;
use App\Models\Campus;
use App\Models\Room;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

final class FacilityController extends Controller
{
    private const RESOURCES = [
        'buildings' => Building::class,
        'rooms' => Room::class,
    ];

    public function index(Request $request, MasterDataTransferService $transferService): Response
    {
        Gate::authorize('viewAny', Building::class);
        Gate::authorize('viewAny', Room::class);

        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'campus_id' => ['nullable', 'integer', 'exists:campuses,id'],
            'type' => ['nullable', Rule::in(['Kelas', 'Laboratorium', 'Aula', 'Kantor', 'Perpustakaan', 'Lainnya'])],
            'status' => ['nullable', Rule::in(['active', 'archived'])],
            'import' => ['nullable', 'uuid'],
        ]);
        $search = trim((string) ($filters['q'] ?? ''));
        $campusId = $filters['campus_id'] ?? null;
        $type = $filters['type'] ?? null;
        $status = $filters['status'] ?? 'active';

        $buildings = Building::query()
            ->when($status === 'archived', fn (Builder $query) => $query->onlyTrashed())
            ->when($search !== '', fn (Builder $query) => $query->where(fn (Builder $query) => $query
                ->where('name', 'like', "%{$search}%")
                ->orWhere('code', 'like', "%{$search}%")))
            ->when($campusId, fn (Builder $query) => $query->where('campus_id', $campusId))
            ->with('campus:id,name')
            ->withCount('rooms')
            ->orderBy('name')
            ->paginate(8, ['id', 'campus_id', 'name', 'code', 'floor_count', 'description', 'is_active', 'deleted_at'], 'buildings_page')
            ->withQueryString();

        $rooms = Room::query()
            ->when($status === 'archived', fn (Builder $query) => $query->onlyTrashed())
            ->when($search !== '', fn (Builder $query) => $query->where(fn (Builder $query) => $query
                ->where('name', 'like', "%{$search}%")
                ->orWhere('code', 'like', "%{$search}%")))
            ->when($campusId, fn (Builder $query) => $query->whereHas('building', fn (Builder $query) => $query->where('campus_id', $campusId)))
            ->when($type, fn (Builder $query) => $query->where('type', $type))
            ->with('building:id,campus_id,name')
            ->orderBy('name')
            ->paginate(8, ['id', 'building_id', 'name', 'code', 'floor', 'type', 'capacity', 'facilities', 'is_active', 'deleted_at'], 'rooms_page')
            ->withQueryString();

        $importToken = $filters['import'] ?? null;
        $storedPreview = $importToken ? $request->session()->get("facility_imports.{$importToken}") : null;
        $importPreview = is_array($storedPreview) && ($storedPreview['user_id'] ?? null) === $request->user()->id
            ? $transferService->present($storedPreview, $importToken)
            : null;

        return Inertia::render('Admin/Facilities', [
            'filters' => [
                'q' => $search,
                'campus_id' => $campusId ? (string) $campusId : '',
                'type' => $type ?? '',
                'status' => $status,
            ],
            'buildings' => $buildings,
            'rooms' => $rooms,
            'campusOptions' => Campus::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'buildingOptions' => Building::query()->with('campus:id,name')->where('is_active', true)->orderBy('name')->get(['id', 'campus_id', 'name', 'floor_count']),
            'summary' => [
                'buildings' => Building::query()->count(),
                'rooms' => Room::query()->count(),
                'capacity' => (int) Room::query()->where('is_active', true)->sum('capacity'),
                'archived' => Building::onlyTrashed()->count() + Room::onlyTrashed()->count(),
            ],
            'abilities' => [
                'buildings' => [
                    'create' => $request->user()->can('create', Building::class),
                    'update' => $request->user()->can('update', new Building),
                    'delete' => $request->user()->can('delete', new Building),
                ],
                'rooms' => [
                    'create' => $request->user()->can('create', Room::class),
                    'update' => $request->user()->can('update', new Room),
                    'delete' => $request->user()->can('delete', new Room),
                ],
            ],
            'importPreview' => $importPreview,
            'transferAbilities' => collect(['buildings', 'rooms'])->mapWithKeys(fn (string $resource): array => [$resource => [
                'import' => $request->user()->can($resource.'.create') && $request->user()->can($resource.'.update'),
                'export' => $request->user()->can($resource.'.view'),
            ]]),
        ]);
    }

    public function store(FacilityRequest $request, string $resource): RedirectResponse
    {
        $class = $this->resourceClass($resource);
        Gate::authorize('create', $class);

        DB::transaction(function () use ($request, $resource, $class): void {
            $model = $class::create($request->validated());
            $this->audit($request, 'created', $resource, $model->getKey(), null, $model->getAttributes());
        });

        return back()->with('success', 'Data sarana berhasil ditambahkan.');
    }

    public function update(FacilityRequest $request, string $resource, int $id): RedirectResponse
    {
        $class = $this->resourceClass($resource);
        $model = $class::query()->findOrFail($id);
        Gate::authorize('update', $model);

        DB::transaction(function () use ($request, $resource, $model): void {
            $old = $model->getAttributes();
            $model->update($request->validated());
            $this->audit($request, 'updated', $resource, $model->getKey(), $old, $model->fresh()->getAttributes());
        });

        return back()->with('success', 'Data sarana berhasil diperbarui.');
    }

    public function destroy(Request $request, string $resource, int $id): RedirectResponse
    {
        $class = $this->resourceClass($resource);
        $model = $class::query()->findOrFail($id);
        Gate::authorize('delete', $model);

        if ($model instanceof Building && $model->rooms()->exists()) {
            throw ValidationException::withMessages([
                'building' => 'Gedung dengan ruangan yang belum diarsipkan tidak dapat diarsipkan.',
            ]);
        }
        if ($model instanceof Room && $model->classGroups()->exists()) {
            throw ValidationException::withMessages([
                'room' => 'Ruangan dengan jadwal yang belum diarsipkan tidak dapat diarsipkan.',
            ]);
        }

        DB::transaction(function () use ($request, $resource, $model): void {
            $old = $model->getAttributes();
            $model->delete();
            $this->audit($request, 'archived', $resource, $model->getKey(), $old, null);
        });

        return back()->with('success', 'Data sarana dipindahkan ke arsip.');
    }

    public function restore(Request $request, string $resource, int $id): RedirectResponse
    {
        $class = $this->resourceClass($resource);
        $model = $class::onlyTrashed()->findOrFail($id);
        Gate::authorize('restore', $model);

        if ($model instanceof Building && ! Campus::query()->whereKey($model->campus_id)->where('is_active', true)->exists()) {
            throw ValidationException::withMessages([
                'building' => 'Aktifkan kampus induk sebelum memulihkan gedung.',
            ]);
        }
        if ($model instanceof Room && ! Building::query()->whereKey($model->building_id)->where('is_active', true)->exists()) {
            throw ValidationException::withMessages([
                'room' => 'Pulihkan dan aktifkan gedung induk sebelum memulihkan ruangan.',
            ]);
        }

        DB::transaction(function () use ($request, $resource, $model): void {
            $model->restore();
            $this->audit($request, 'restored', $resource, $model->getKey(), null, $model->fresh()->getAttributes());
        });

        return back()->with('success', 'Data sarana berhasil dipulihkan.');
    }

    public function bulk(FacilityBulkRequest $request, string $resource): RedirectResponse
    {
        $class = $this->resourceClass($resource);
        $data = $request->validated();
        $action = $data['action'];
        $ids = $data['ids'];

        DB::transaction(function () use ($request, $resource, $class, $action, $ids): void {
            $query = $action === 'restore' ? $class::onlyTrashed() : $class::query();
            $models = $query->whereIn('id', $ids)->lockForUpdate()->get();
            if ($models->count() !== count($ids)) throw ValidationException::withMessages(['bulk' => 'Sebagian data tidak ditemukan atau statusnya sudah berubah. Muat ulang halaman.']);

            foreach ($models as $model) {
                if ($action === 'archive' && $model instanceof Building && $model->rooms()->exists()) throw ValidationException::withMessages(['bulk' => "Gedung {$model->code} masih memiliki ruangan aktif."]);
                if ($action === 'archive' && $model instanceof Room && $model->classGroups()->exists()) throw ValidationException::withMessages(['bulk' => "Ruangan {$model->code} masih digunakan jadwal aktif."]);
                if ($action === 'restore' && $model instanceof Building && ! Campus::query()->whereKey($model->campus_id)->where('is_active', true)->exists()) throw ValidationException::withMessages(['bulk' => "Kampus induk gedung {$model->code} belum aktif."]);
                if ($action === 'restore' && $model instanceof Room && ! Building::query()->whereKey($model->building_id)->where('is_active', true)->exists()) throw ValidationException::withMessages(['bulk' => "Gedung induk ruangan {$model->code} belum aktif."]);
            }

            foreach ($models as $model) {
                $old = $model->getAttributes();
                if ($action === 'restore') $model->restore();
                else $model->delete();
                $this->audit($request, $action === 'restore' ? 'restored' : 'archived', $resource, $model->getKey(), $action === 'archive' ? $old : null, $action === 'restore' ? $model->fresh()->getAttributes() : null);
            }
        }, 3);

        return back()->with('success', count($ids).' data sarana berhasil '.($action === 'restore' ? 'dipulihkan.' : 'diarsipkan.'));
    }

    /** @return class-string<Building|Room> */
    private function resourceClass(string $resource): string
    {
        abort_unless(array_key_exists($resource, self::RESOURCES), 404);

        return self::RESOURCES[$resource];
    }

    private function audit(Request $request, string $action, string $resource, int $id, ?array $old, ?array $new): void
    {
        DB::table('audit_logs')->insert([
            'user_id' => $request->user()->id,
            'module' => 'facilities',
            'action' => $action,
            'record_type' => $resource,
            'record_id' => (string) $id,
            'old_data' => $old ? json_encode($old) : null,
            'new_data' => $new ? json_encode($new) : null,
            'ip_address' => $request->ip(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
