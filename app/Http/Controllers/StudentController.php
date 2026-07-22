<?php

namespace App\Http\Controllers;

use App\Domain\Academic\StudentStatusService;
use App\Domain\MasterData\MasterDataTransferService;
use App\Http\Requests\StudentBulkRequest;
use App\Http\Requests\StudentRequest;
use App\Http\Requests\StudentStatusRequest;
use App\Models\AcademicTerm;
use App\Models\Lecturer;
use App\Models\Program;
use App\Models\Student;
use App\Models\StudentStatusHistory;
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

final class StudentController extends Controller
{
    public function index(Request $request, MasterDataTransferService $transferService): Response
    {
        Gate::authorize('viewAny', Student::class);
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'program_id' => ['nullable', 'integer', 'exists:programs,id'],
            'student_status' => ['nullable', Rule::in(['Aktif', 'Cuti', 'Lulus', 'Nonaktif'])],
            'cohort_year' => ['nullable', 'integer', 'min:2000', 'max:'.(now()->year + 1)],
            'archive' => ['nullable', Rule::in(['available', 'archived'])],
            'selected' => ['nullable', 'integer'],
            'import' => ['nullable', 'uuid'],
        ]);
        $search = trim((string) ($filters['q'] ?? ''));
        $programId = $filters['program_id'] ?? null;
        $status = $filters['student_status'] ?? null;
        $cohort = $filters['cohort_year'] ?? null;
        $archive = $filters['archive'] ?? 'available';

        $base = Student::query()
            ->when($archive === 'archived', fn (Builder $query) => $query->onlyTrashed())
            ->when($search !== '', fn (Builder $query) => $query->where(fn (Builder $query) => $query->where('nim', 'like', "%{$search}%")->orWhereHas('user', fn (Builder $query) => $query->where('name', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%"))))
            ->when($programId, fn (Builder $query) => $query->where('program_id', $programId))
            ->when($status, fn (Builder $query) => $query->where('status', $status))
            ->when($cohort, fn (Builder $query) => $query->where('cohort_year', $cohort));

        $selectedId = isset($filters['selected']) ? (clone $base)->whereKey($filters['selected'])->value('id') : (clone $base)->orderBy('nim')->value('id');
        $selected = $selectedId ? Student::withTrashed()->with(['user:id,name,email', 'program:id,name,code', 'academicAdvisor:id,name,nidn', 'admissionTerm:id,name,code', 'statusHistories.academicTerm:id,name,code', 'statusHistories.changedBy:id,name'])->find($selectedId) : null;

        $importToken = $filters['import'] ?? null;
        $storedPreview = $importToken ? $request->session()->get("student_imports.{$importToken}") : null;
        $importPreview = is_array($storedPreview) && ($storedPreview['user_id'] ?? null) === $request->user()->id
            ? $transferService->present($storedPreview, $importToken)
            : null;

        return Inertia::render('Admin/Students', [
            'filters' => ['q' => $search, 'program_id' => $programId ? (string) $programId : '', 'student_status' => $status ?? '', 'cohort_year' => $cohort ? (string) $cohort : '', 'archive' => $archive, 'selected' => $selectedId],
            'students' => (clone $base)->with(['user:id,name,email', 'program:id,name,code', 'academicAdvisor:id,name'])->orderBy('nim')->paginate(12)->withQueryString(),
            'selectedStudent' => $selected,
            'programOptions' => Program::query()->where('is_active', true)->orderBy('name')->get(['id', 'name', 'code']),
            'termOptions' => AcademicTerm::query()->orderByDesc('starts_on')->get(['id', 'name', 'code']),
            'advisorOptions' => Lecturer::query()->where('is_active', true)->orderBy('name')->get(['id', 'program_id', 'name', 'nidn']),
            'userOptions' => User::query()->where('is_active', true)->orderBy('name')->get(['id', 'name', 'email']),
            'summary' => ['total' => Student::query()->count(), 'active' => Student::query()->where('status', 'Aktif')->count(), 'leave' => Student::query()->where('status', 'Cuti')->count(), 'graduates' => Student::query()->where('status', 'Lulus')->count(), 'archived' => Student::onlyTrashed()->count()],
            'abilities' => ['create' => $request->user()->can('create', Student::class), 'update' => $request->user()->can('update', new Student), 'delete' => $request->user()->can('delete', new Student)],
            'transferAbility' => ['import' => $request->user()->can('students.create') && $request->user()->can('students.update'), 'export' => $request->user()->can('students.export')],
            'importPreview' => $importPreview,
        ]);
    }

    public function store(StudentRequest $request): RedirectResponse
    {
        Gate::authorize('create', Student::class);
        $student = DB::transaction(function () use ($request): Student {
            $student = Student::create([...$request->validated(), 'status' => 'Aktif']);
            StudentStatusHistory::create(['student_id' => $student->id, 'academic_term_id' => $student->admission_term_id, 'changed_by_user_id' => $request->user()->id, 'from_status' => null, 'to_status' => 'Aktif', 'effective_on' => now()->toDateString(), 'reason' => 'Pembuatan data mahasiswa']);
            $student->user->assignRole(Role::findOrCreate('Mahasiswa', 'web'));
            $this->audit($request, 'created', $student->id, null, $student->getAttributes());
            return $student;
        });

        return to_route('admin.students', ['selected' => $student->id])->with('success', 'Data mahasiswa berhasil ditambahkan.');
    }

    public function update(StudentRequest $request, Student $student): RedirectResponse
    {
        Gate::authorize('update', $student);
        DB::transaction(function () use ($request, $student): void {
            $old = $student->getAttributes();
            $student->update($request->validated());
            $student->user->assignRole(Role::findOrCreate('Mahasiswa', 'web'));
            $this->audit($request, 'updated', $student->id, $old, $student->fresh()->getAttributes());
        });
        return back()->with('success', 'Data mahasiswa berhasil diperbarui.');
    }

    public function transition(StudentStatusRequest $request, Student $student, StudentStatusService $service): RedirectResponse
    {
        Gate::authorize('update', $student);
        DB::transaction(function () use ($request, $student, $service): void {
            $old = $student->getAttributes();
            $history = $service->transition($student, $request->string('status')->toString(), $request->string('reason')->toString(), $request->date('effective_on')->toDateString(), $request->integer('academic_term_id') ?: null, $request->user());
            $this->audit($request, 'status_changed', $student->id, $old, ['student' => $student->fresh()->getAttributes(), 'history_id' => $history->id]);
        });
        return back()->with('success', 'Status mahasiswa berhasil diperbarui.');
    }

    public function destroy(Request $request, Student $student): RedirectResponse
    {
        Gate::authorize('delete', $student);
        if (in_array($student->status, ['Aktif', 'Cuti'], true)) throw ValidationException::withMessages(['student' => 'Ubah status mahasiswa menjadi Lulus atau Nonaktif sebelum mengarsipkan.']);
        DB::transaction(function () use ($request, $student): void { $old = $student->getAttributes(); $student->delete(); $this->audit($request, 'archived', $student->id, $old, null); });
        return to_route('admin.students')->with('success', 'Data mahasiswa dipindahkan ke arsip.');
    }

    public function restore(Request $request, int $student): RedirectResponse
    {
        $model = Student::onlyTrashed()->findOrFail($student);
        Gate::authorize('restore', $model);
        if (! User::query()->whereKey($model->user_id)->where('is_active', true)->exists() || ! Program::query()->whereKey($model->program_id)->where('is_active', true)->exists()) throw ValidationException::withMessages(['student' => 'Aktifkan akun dan program studi sebelum memulihkan mahasiswa.']);
        DB::transaction(function () use ($request, $model): void { $model->restore(); $this->audit($request, 'restored', $model->id, null, $model->fresh()->getAttributes()); });
        return to_route('admin.students', ['selected' => $model->id])->with('success', 'Data mahasiswa berhasil dipulihkan.');
    }

    public function bulk(StudentBulkRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $ids = $data['ids'];
        $action = $data['action'];

        DB::transaction(function () use ($request, $ids, $action): void {
            $query = $action === 'restore' ? Student::onlyTrashed() : Student::query();
            $students = $query->whereIn('id', $ids)->lockForUpdate()->get();
            if ($students->count() !== count($ids)) throw ValidationException::withMessages(['bulk' => 'Sebagian mahasiswa tidak ditemukan atau statusnya sudah berubah. Muat ulang halaman.']);

            foreach ($students as $student) {
                if ($action === 'archive' && in_array($student->status, ['Aktif', 'Cuti'], true)) throw ValidationException::withMessages(['bulk' => "Mahasiswa {$student->nim} harus berstatus Lulus atau Nonaktif sebelum diarsipkan."]);
                if ($action === 'restore' && ! User::query()->whereKey($student->user_id)->where('is_active', true)->exists()) throw ValidationException::withMessages(['bulk' => "Akun mahasiswa {$student->nim} belum aktif."]);
                if ($action === 'restore' && ! Program::query()->whereKey($student->program_id)->where('is_active', true)->exists()) throw ValidationException::withMessages(['bulk' => "Program studi mahasiswa {$student->nim} belum aktif."]);
            }

            foreach ($students as $student) {
                $old = $student->getAttributes();
                if ($action === 'restore') $student->restore();
                else $student->delete();
                $this->audit($request, $action === 'restore' ? 'restored' : 'archived', $student->id, $action === 'archive' ? $old : null, $action === 'restore' ? $student->fresh()->getAttributes() : null);
            }
        }, 3);

        return back()->with('success', count($ids).' mahasiswa berhasil '.($action === 'restore' ? 'dipulihkan.' : 'diarsipkan.'));
    }

    private function audit(Request $request, string $action, int $id, ?array $old, ?array $new): void
    {
        DB::table('audit_logs')->insert(['user_id' => $request->user()->id, 'module' => 'students', 'action' => $action, 'record_type' => 'student', 'record_id' => (string) $id, 'old_data' => $old ? json_encode($old) : null, 'new_data' => $new ? json_encode($new) : null, 'ip_address' => $request->ip(), 'created_at' => now(), 'updated_at' => now()]);
    }
}
