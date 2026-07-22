<?php

namespace App\Http\Controllers;

use App\Domain\Academic\AttendanceService;
use App\Http\Requests\AttendanceRecordsRequest;
use App\Http\Requests\AttendanceSessionRequest;
use App\Models\AcademicTerm;
use App\Models\AttendanceSession;
use App\Models\ClassGroup;
use App\Services\NotificationService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

final class AttendanceController extends Controller
{
    public function index(Request $request): Response
    {
        abort_unless($request->user()->can('attendance.view'), 403);
        $filters = $request->validate(['academic_term_id' => ['nullable', 'integer', Rule::exists('academic_terms', 'id')->whereNull('deleted_at')], 'q' => ['nullable', 'string', 'max:100'], 'selected' => ['nullable', 'integer', 'exists:class_groups,id']]);
        $user = $request->user(); $isStudent = $user->active_role === 'Mahasiswa'; $search = trim((string) ($filters['q'] ?? ''));
        abort_unless($isStudent || in_array($user->active_role, ['Admin', 'Prodi', 'Dosen'], true), 403);
        $classes = ClassGroup::query()->with(['course:id,program_id,code,name,credits', 'course.program:id,code,name', 'academicTerm:id,code,name,semester,is_active', 'lecturer:id,name,nidn'])->withCount('attendanceSessions')
            ->when(isset($filters['academic_term_id']), fn (Builder $query) => $query->where('academic_term_id', $filters['academic_term_id']))
            ->when($search !== '', fn (Builder $query) => $query->where(fn (Builder $query) => $query->where('name', 'like', "%{$search}%")->orWhereHas('course', fn (Builder $query) => $query->where('code', 'like', "%{$search}%")->orWhere('name', 'like', "%{$search}%"))))
            ->when($user->active_role === 'Dosen', fn (Builder $query) => $query->where('lecturer_id', $user->lecturer?->id ?? 0))
            ->when($isStudent, fn (Builder $query) => $query->whereHas('enrollments', fn (Builder $query) => $query->where('status', 'enrolled')->whereHas('registration', fn (Builder $query) => $query->where('student_id', $user->student?->id ?? 0)->where('status', 'approved'))));
        $selectedId = isset($filters['selected']) ? (clone $classes)->whereKey($filters['selected'])->value('id') : (clone $classes)->latest('academic_term_id')->value('id');
        $selected = $selectedId ? (clone $classes)->find($selectedId) : null;
        if ($selected) {
            Gate::authorize('viewAttendance', $selected);
            $sessions = AttendanceSession::query()->where('class_group_id', $selected->id)->with(['creator:id,name']);
            if ($isStudent) {
                $enrollmentId = $this->studentEnrollmentId($request, $selected);
                $sessions->with(['records' => fn ($query) => $query->where('course_enrollment_id', $enrollmentId)]);
            } else {
                $sessions->with(['records.enrollment.registration.student.user:id,name,email', 'records.enrollment.registration.student:id,user_id,nim']);
            }
            $loadedSessions = $sessions->orderByDesc('meeting_number')->get();
            if (! $isStudent) $loadedSessions->each(fn (AttendanceSession $session) => $session->setAttribute('manager_access_code', $session->access_code));
            $selected->setRelation('attendanceSessions', $loadedSessions);
        }
        return Inertia::render('Academic/Attendance', [
            'mode' => $isStudent ? 'student' : 'manager', 'filters' => ['academic_term_id' => (string) ($filters['academic_term_id'] ?? ''), 'q' => $search, 'selected' => $selectedId],
            'termOptions' => AcademicTerm::query()->latest('starts_on')->get(['id', 'code', 'name', 'semester', 'is_active']), 'classGroups' => $classes->latest('academic_term_id')->paginate(12)->withQueryString(), 'selectedClass' => $selected,
            'abilities' => ['manage' => $selected ? $user->can('manageAttendance', $selected) : false],
        ]);
    }

    public function store(AttendanceSessionRequest $request, ClassGroup $classGroup, AttendanceService $service): RedirectResponse
    {
        Gate::authorize('manageAttendance', $classGroup); $session = $service->createSession($classGroup, $request->validated(), $request->user()); $this->audit($request, 'session_created', $session, ['meeting_number' => $session->meeting_number]);
        return back()->with('success', 'Pertemuan presensi berhasil dibuat. Kode akses: '.$session->access_code);
    }

    public function update(AttendanceSessionRequest $request, ClassGroup $classGroup, AttendanceSession $session, AttendanceService $service): RedirectResponse
    {
        $this->assertChild($classGroup, $session); Gate::authorize('manageAttendance', $classGroup); $service->updateSession($session, $request->validated()); $this->audit($request, 'session_updated', $session, ['meeting_number' => $session->meeting_number]);
        return back()->with('success', 'Pertemuan berhasil diperbarui.');
    }

    public function destroy(Request $request, ClassGroup $classGroup, AttendanceSession $session): RedirectResponse
    {
        $this->assertChild($classGroup, $session); Gate::authorize('manageAttendance', $classGroup);
        if ($session->status !== 'draft' || $session->records()->where('status', '!=', 'unmarked')->exists()) throw ValidationException::withMessages(['session' => 'Hanya pertemuan draf yang belum memiliki presensi dapat dihapus.']);
        $session->delete(); $this->audit($request, 'session_deleted', $session, []); return back()->with('success', 'Pertemuan draf berhasil dihapus.');
    }

    public function transition(Request $request, ClassGroup $classGroup, AttendanceSession $session, AttendanceService $service): RedirectResponse
    {
        $this->assertChild($classGroup, $session); Gate::authorize('manageAttendance', $classGroup); $data = $request->validate(['status' => ['required', Rule::in(['open', 'closed'])]]); $service->transition($session, $data['status'], $request->user());
        if ($data['status'] === 'open') app(NotificationService::class)->classStudents($classGroup, 'attendance', 'Presensi telah dibuka', 'Presensi pertemuan '.$session->meeting_number.' '.$classGroup->course->name.' telah dibuka.', '/academic/attendance?selected='.$classGroup->id);
        $this->audit($request, 'session_'.$data['status'], $session, []); return back()->with('success', $data['status'] === 'open' ? 'Presensi berhasil dibuka.' : 'Presensi ditutup; peserta yang belum tercatat otomatis ditandai tidak hadir.');
    }

    public function saveRecords(AttendanceRecordsRequest $request, ClassGroup $classGroup, AttendanceSession $session, AttendanceService $service): RedirectResponse
    {
        $this->assertChild($classGroup, $session); Gate::authorize('manageAttendance', $classGroup); $service->saveRecords($session, $request->validated('records'), $request->user()); $this->audit($request, 'records_updated', $session, ['count' => count($request->validated('records'))]);
        return back()->with('success', 'Presensi seluruh peserta berhasil disimpan.');
    }

    public function checkIn(Request $request, ClassGroup $classGroup, AttendanceSession $session, AttendanceService $service): RedirectResponse
    {
        $this->assertChild($classGroup, $session); Gate::authorize('viewAttendance', $classGroup); abort_unless($request->user()->active_role === 'Mahasiswa', 403); $data = $request->validate(['code' => ['required', 'digits_between:4,8']]);
        $record = $service->selfCheckIn($session, $this->studentEnrollmentId($request, $classGroup), $data['code'], $request->user()); $this->audit($request, 'student_check_in', $session, ['record_id' => $record->id, 'status' => $record->status]);
        return back()->with('success', $record->status === 'late' ? 'Presensi tercatat sebagai terlambat.' : 'Kehadiran berhasil dicatat.');
    }

    private function studentEnrollmentId(Request $request, ClassGroup $classGroup): int { $id = $classGroup->enrollments()->where('status', 'enrolled')->whereHas('registration', fn (Builder $query) => $query->where('student_id', $request->user()->student?->id ?? 0)->where('status', 'approved'))->value('id'); abort_unless($id, 403); return (int) $id; }
    private function assertChild(ClassGroup $classGroup, AttendanceSession $session): void { abort_unless((int) $session->class_group_id === (int) $classGroup->id, 404); }
    private function audit(Request $request, string $action, AttendanceSession $session, array $data): void { DB::table('audit_logs')->insert(['user_id' => $request->user()->id, 'module' => 'attendance', 'action' => $action, 'record_type' => 'attendance_session', 'record_id' => (string) $session->id, 'new_data' => json_encode(['class_group_id' => $session->class_group_id, ...$data]), 'ip_address' => $request->ip(), 'created_at' => now(), 'updated_at' => now()]); }
}
