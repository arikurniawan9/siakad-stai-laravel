<?php

namespace App\Http\Controllers;

use App\Domain\MasterData\MasterDataTransferService;
use App\Http\Requests\AcademicScheduleRequest;
use App\Http\Requests\LecturerBulkRequest;
use App\Http\Requests\LecturerRequest;
use App\Models\AcademicTerm;
use App\Models\ClassGroup;
use App\Models\Course;
use App\Models\Lecturer;
use App\Models\Program;
use App\Models\Room;
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

final class AcademicScheduleController extends Controller
{
    public function index(Request $request, MasterDataTransferService $transferService): Response
    {
        Gate::authorize('viewAny', Lecturer::class);
        Gate::authorize('viewAny', ClassGroup::class);

        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'program_id' => ['nullable', 'integer', 'exists:programs,id'],
            'academic_term_id' => ['nullable', 'integer', 'exists:academic_terms,id'],
            'status' => ['nullable', Rule::in(['available', 'archived'])],
            'import' => ['nullable', 'uuid'],
        ]);
        $search = trim((string) ($filters['q'] ?? ''));
        $programId = $filters['program_id'] ?? null;
        $termId = $filters['academic_term_id'] ?? null;
        $status = $filters['status'] ?? 'available';

        $lecturers = Lecturer::query()
            ->when($status === 'archived', fn (Builder $query) => $query->onlyTrashed())
            ->when($search !== '', fn (Builder $query) => $query->where(fn (Builder $query) => $query->where('name', 'like', "%{$search}%")->orWhere('nidn', 'like', "%{$search}%")))
            ->when($programId, fn (Builder $query) => $query->where('program_id', $programId))
            ->with(['program:id,name,code', 'user:id,name,email'])
            ->withCount('classGroups')
            ->orderBy('name')
            ->paginate(8, ['id', 'user_id', 'program_id', 'name', 'nidn', 'employee_number', 'academic_title', 'employment_status', 'expertise', 'is_active', 'deleted_at'], 'lecturers_page')
            ->withQueryString();

        $schedules = ClassGroup::query()
            ->when($status === 'archived', fn (Builder $query) => $query->onlyTrashed())
            ->when($search !== '', fn (Builder $query) => $query->where(fn (Builder $query) => $query
                ->where('name', 'like', "%{$search}%")
                ->orWhereHas('course', fn (Builder $query) => $query->where('name', 'like', "%{$search}%")->orWhere('code', 'like', "%{$search}%"))))
            ->when($programId, fn (Builder $query) => $query->whereHas('course', fn (Builder $query) => $query->where('program_id', $programId)))
            ->when($termId, fn (Builder $query) => $query->where('academic_term_id', $termId))
            ->with([
                'academicTerm:id,name,code',
                'course:id,program_id,name,code,credits',
                'course.program:id,name,code',
                'lecturer:id,name,nidn',
                'assignedRoom:id,building_id,name,code,capacity',
                'assignedRoom.building:id,name',
            ])
            ->orderBy('day')
            ->orderBy('starts_at')
            ->paginate(10, ['id', 'academic_term_id', 'course_id', 'lecturer_id', 'room_id', 'name', 'capacity', 'enrolled_count', 'day', 'starts_at', 'ends_at', 'is_active', 'deleted_at'], 'schedules_page')
            ->withQueryString();

        $importToken = $filters['import'] ?? null;
        $storedPreview = $importToken ? $request->session()->get("lecturer_imports.{$importToken}") : null;
        $importPreview = is_array($storedPreview) && ($storedPreview['user_id'] ?? null) === $request->user()->id
            ? $transferService->present($storedPreview, $importToken)
            : null;

        return Inertia::render('Admin/AcademicSchedules', [
            'filters' => [
                'q' => $search,
                'program_id' => $programId ? (string) $programId : '',
                'academic_term_id' => $termId ? (string) $termId : '',
                'status' => $status,
            ],
            'lecturers' => $lecturers,
            'schedules' => $schedules,
            'programOptions' => Program::query()->where('is_active', true)->orderBy('name')->get(['id', 'name', 'code']),
            'termOptions' => AcademicTerm::query()->orderByDesc('starts_on')->get(['id', 'name', 'code', 'is_active']),
            'courseOptions' => Course::query()->where('is_active', true)->with('program:id,name,code')->orderBy('code')->get(['id', 'program_id', 'name', 'code', 'credits']),
            'lecturerOptions' => Lecturer::query()->where('is_active', true)->orderBy('name')->get(['id', 'name', 'nidn']),
            'roomOptions' => Room::query()->where('is_active', true)->with('building:id,name')->orderBy('name')->get(['id', 'building_id', 'name', 'code', 'capacity']),
            'userOptions' => User::query()->where('is_active', true)->orderBy('name')->get(['id', 'name', 'email']),
            'summary' => [
                'lecturers' => Lecturer::query()->count(),
                'schedules' => ClassGroup::query()->count(),
                'active_term_schedules' => ClassGroup::query()->whereHas('academicTerm', fn (Builder $query) => $query->where('is_active', true))->count(),
                'weekly_capacity' => (int) ClassGroup::query()->where('is_active', true)->sum('capacity'),
                'archived' => Lecturer::onlyTrashed()->count() + ClassGroup::onlyTrashed()->count(),
            ],
            'abilities' => [
                'lecturers' => [
                    'create' => $request->user()->can('create', Lecturer::class),
                    'update' => $request->user()->can('update', new Lecturer),
                    'delete' => $request->user()->can('delete', new Lecturer),
                ],
                'schedules' => [
                    'create' => $request->user()->can('create', ClassGroup::class),
                    'update' => $request->user()->can('update', new ClassGroup),
                    'delete' => $request->user()->can('delete', new ClassGroup),
                ],
            ],
            'lecturerTransferAbility' => [
                'import' => $request->user()->can('lecturers.create') && $request->user()->can('lecturers.update'),
                'export' => $request->user()->can('lecturers.view'),
            ],
            'importPreview' => $importPreview,
        ]);
    }

    public function storeLecturer(LecturerRequest $request): RedirectResponse
    {
        Gate::authorize('create', Lecturer::class);
        DB::transaction(function () use ($request): void {
            $lecturer = Lecturer::create($request->validated());
            if ($lecturer->user_id && ($user = $lecturer->user)) {
                $user->assignRole(Role::findOrCreate('Dosen', 'web'));
                if (blank($user->active_role)) $user->update(['active_role' => 'Dosen']);
            }
            $this->audit($request, 'created', 'lecturer', $lecturer->id, null, $lecturer->getAttributes());
        });

        return back()->with('success', 'Data dosen berhasil ditambahkan.');
    }

    public function updateLecturer(LecturerRequest $request, Lecturer $lecturer): RedirectResponse
    {
        Gate::authorize('update', $lecturer);
        DB::transaction(function () use ($request, $lecturer): void {
            $old = $lecturer->getAttributes();
            $lecturer->update($request->validated());
            if ($lecturer->user_id && ($user = $lecturer->user)) {
                $user->assignRole(Role::findOrCreate('Dosen', 'web'));
                if (blank($user->active_role)) $user->update(['active_role' => 'Dosen']);
            }
            $this->audit($request, 'updated', 'lecturer', $lecturer->id, $old, $lecturer->fresh()->getAttributes());
        });

        return back()->with('success', 'Data dosen berhasil diperbarui.');
    }

    public function destroyLecturer(Request $request, Lecturer $lecturer): RedirectResponse
    {
        Gate::authorize('delete', $lecturer);
        if ($lecturer->classGroups()->exists()) {
            throw ValidationException::withMessages(['lecturer' => 'Dosen dengan jadwal yang belum diarsipkan tidak dapat diarsipkan.']);
        }
        $this->archive($request, $lecturer, 'lecturer');

        return back()->with('success', 'Data dosen dipindahkan ke arsip.');
    }

    public function restoreLecturer(Request $request, int $lecturer): RedirectResponse
    {
        $model = Lecturer::onlyTrashed()->findOrFail($lecturer);
        Gate::authorize('restore', $model);
        if (! Program::query()->whereKey($model->program_id)->where('is_active', true)->exists()) throw ValidationException::withMessages(['program' => 'Program studi dosen belum aktif.']);
        if ($model->user_id && ! User::query()->whereKey($model->user_id)->where('is_active', true)->exists()) throw ValidationException::withMessages(['user' => 'Akun dosen belum tersedia atau belum aktif.']);
        $this->restore($request, $model, 'lecturer');

        return back()->with('success', 'Data dosen berhasil dipulihkan.');
    }

    public function bulkLecturers(LecturerBulkRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $ids = $data['ids'];
        $action = $data['action'];

        DB::transaction(function () use ($request, $ids, $action): void {
            $query = $action === 'restore' ? Lecturer::onlyTrashed() : Lecturer::query();
            $lecturers = $query->whereIn('id', $ids)->lockForUpdate()->get();
            if ($lecturers->count() !== count($ids)) throw ValidationException::withMessages(['bulk' => 'Sebagian dosen tidak ditemukan atau statusnya sudah berubah. Muat ulang halaman.']);

            foreach ($lecturers as $lecturer) {
                if ($action === 'archive' && $lecturer->classGroups()->exists()) throw ValidationException::withMessages(['bulk' => "Dosen {$lecturer->nidn} masih memiliki jadwal aktif."]);
                if ($action === 'restore' && ! Program::query()->whereKey($lecturer->program_id)->where('is_active', true)->exists()) throw ValidationException::withMessages(['bulk' => "Program studi dosen {$lecturer->nidn} belum aktif."]);
                if ($action === 'restore' && $lecturer->user_id && ! User::query()->whereKey($lecturer->user_id)->where('is_active', true)->exists()) throw ValidationException::withMessages(['bulk' => "Akun dosen {$lecturer->nidn} belum aktif."]);
            }

            foreach ($lecturers as $lecturer) {
                $old = $lecturer->getAttributes();
                if ($action === 'restore') $lecturer->restore();
                else $lecturer->delete();
                $this->audit($request, $action === 'restore' ? 'restored' : 'archived', 'lecturer', $lecturer->id, $action === 'archive' ? $old : null, $action === 'restore' ? $lecturer->fresh()->getAttributes() : null);
            }
        }, 3);

        return back()->with('success', count($ids).' dosen berhasil '.($action === 'restore' ? 'dipulihkan.' : 'diarsipkan.'));
    }

    public function storeSchedule(AcademicScheduleRequest $request): RedirectResponse
    {
        Gate::authorize('create', ClassGroup::class);
        DB::transaction(function () use ($request): void {
            $data = $request->validated();
            $this->lockResourcesAndEnsureNoConflict($data);
            $schedule = ClassGroup::create($data);
            $this->audit($request, 'created', 'schedule', $schedule->id, null, $schedule->getAttributes());
        });

        return back()->with('success', 'Jadwal kuliah berhasil ditambahkan.');
    }

    public function updateSchedule(AcademicScheduleRequest $request, ClassGroup $schedule): RedirectResponse
    {
        Gate::authorize('update', $schedule);
        DB::transaction(function () use ($request, $schedule): void {
            $data = $request->validated();
            $this->lockResourcesAndEnsureNoConflict($data, $schedule->id);
            $old = $schedule->getAttributes();
            $schedule->update($data);
            $this->audit($request, 'updated', 'schedule', $schedule->id, $old, $schedule->fresh()->getAttributes());
        });

        return back()->with('success', 'Jadwal kuliah berhasil diperbarui.');
    }

    public function destroySchedule(Request $request, ClassGroup $schedule): RedirectResponse
    {
        Gate::authorize('delete', $schedule);
        $this->archive($request, $schedule, 'schedule');

        return back()->with('success', 'Jadwal kuliah dipindahkan ke arsip.');
    }

    public function restoreSchedule(Request $request, int $schedule): RedirectResponse
    {
        $model = ClassGroup::onlyTrashed()->findOrFail($schedule);
        Gate::authorize('restore', $model);
        $data = $model->only(['academic_term_id', 'lecturer_id', 'room_id', 'day', 'starts_at', 'ends_at']);

        DB::transaction(function () use ($request, $model, $data): void {
            $this->lockResourcesAndEnsureNoConflict($data, $model->id);
            $model->restore();
            $this->audit($request, 'restored', 'schedule', $model->id, null, $model->fresh()->getAttributes());
        });

        return back()->with('success', 'Jadwal kuliah berhasil dipulihkan.');
    }

    private function lockResourcesAndEnsureNoConflict(array $data, ?int $exceptId = null): void
    {
        Room::query()->whereKey($data['room_id'])->where('is_active', true)->lockForUpdate()->firstOrFail();
        Lecturer::query()->whereKey($data['lecturer_id'])->where('is_active', true)->lockForUpdate()->firstOrFail();

        $overlap = ClassGroup::query()
            ->where('academic_term_id', $data['academic_term_id'])
            ->where('day', $data['day'])
            ->where('starts_at', '<', $data['ends_at'])
            ->where('ends_at', '>', $data['starts_at'])
            ->when($exceptId, fn (Builder $query) => $query->whereKeyNot($exceptId));

        $errors = [];
        if ((clone $overlap)->where('room_id', $data['room_id'])->exists()) {
            $errors['room_id'] = 'Ruangan sudah digunakan pada waktu yang beririsan.';
        }
        if ((clone $overlap)->where('lecturer_id', $data['lecturer_id'])->exists()) {
            $errors['lecturer_id'] = 'Dosen sudah mengajar pada waktu yang beririsan.';
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    private function archive(Request $request, object $model, string $type): void
    {
        DB::transaction(function () use ($request, $model, $type): void {
            $old = $model->getAttributes();
            $model->delete();
            $this->audit($request, 'archived', $type, $model->getKey(), $old, null);
        });
    }

    private function restore(Request $request, object $model, string $type): void
    {
        DB::transaction(function () use ($request, $model, $type): void {
            $model->restore();
            $this->audit($request, 'restored', $type, $model->getKey(), null, $model->fresh()->getAttributes());
        });
    }

    private function audit(Request $request, string $action, string $type, int $id, ?array $old, ?array $new): void
    {
        DB::table('audit_logs')->insert([
            'user_id' => $request->user()->id,
            'module' => 'academic_schedules',
            'action' => $action,
            'record_type' => $type,
            'record_id' => (string) $id,
            'old_data' => $old ? json_encode($old) : null,
            'new_data' => $new ? json_encode($new) : null,
            'ip_address' => $request->ip(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
