<?php

namespace App\Http\Controllers;

use App\Http\Requests\AcademicGuidanceAppointmentRequest;
use App\Http\Requests\AcademicGuidanceNoteRequest;
use App\Http\Requests\EarlyWarningDecisionRequest;
use App\Http\Requests\GuidanceAvailabilityRequest;
use App\Http\Requests\StudentInterventionRequest;
use App\Models\AcademicGuidanceAppointment;
use App\Models\AcademicGuidanceNote;
use App\Models\CourseEnrollment;
use App\Models\Student;
use App\Models\StudentEarlyWarning;
use App\Models\GuidanceAvailabilitySlot;
use App\Models\StudentInterventionPlan;
use App\Services\NotificationService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

final class AcademicGuidanceController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorizeView($request);
        $user = $request->user();
        $studentId = $user->active_role === 'Mahasiswa' ? ($user->student?->id ?? 0) : null;
        $studentQuery = Student::query()->with(['user:id,name,email', 'program:id,code,name', 'academicAdvisor:id,name'])->where('status', '!=', 'Alumni')
            ->when($studentId, fn (Builder $q) => $q->whereKey($studentId))
            ->when($user->active_role === 'Dosen', fn (Builder $q) => $q->where('academic_advisor_id', $user->lecturer?->id ?? 0));
        $students = $studentQuery->orderBy('nim')->get();
        $studentIds = $students->modelKeys();
        $this->refreshWarnings($students);
        $appointments = AcademicGuidanceAppointment::with(['student.user:id,name', 'student.program:id,code', 'lecturer:id,name'])->whereIn('student_id', $studentIds)->when($user->active_role === 'Dosen', fn (Builder $q) => $q->where('lecturer_id', $user->lecturer?->id ?? 0))->latest('starts_at')->limit(40)->get();
        $notes = AcademicGuidanceNote::with(['student.user:id,name', 'lecturer:id,name'])->whereIn('student_id', $studentIds)->latest()->limit(40)->get();
        $warnings = StudentEarlyWarning::with(['student.user:id,name', 'student.program:id,code', 'assignedLecturer:id,name'])->whereIn('student_id', $studentIds)->whereIn('status', ['open', 'acknowledged'])->latest('severity')->latest()->get();
        return Inertia::render('Academic/Guidance', [
            'mode' => $user->active_role === 'Mahasiswa' ? 'student' : 'manager',
            'students' => $students,
            'appointments' => $appointments,
            'notes' => $notes,
            'warnings' => $warnings,
            'availability' => GuidanceAvailabilitySlot::query()->where('lecturer_id', $user->lecturer?->id ?? 0)->where('is_active', true)->orderBy('weekday')->orderBy('starts_at')->get(),
            'interventions' => StudentInterventionPlan::with(['student.user:id,name', 'assignedLecturer:id,name'])->whereIn('student_id', $studentIds)->whereIn('status', ['open', 'in_progress'])->latest()->get(),
            'stats' => ['students' => $students->count(), 'appointments' => $appointments->whereIn('status', ['pending', 'confirmed'])->count(), 'openWarnings' => $warnings->whereIn('status', ['open', 'acknowledged'])->count(), 'followUps' => $notes->where('follow_up_status', 'pending')->count()],
            'abilities' => ['createAppointment' => $user->can('guidance.create'), 'manage' => $user->can('guidance.update'), 'canWriteNote' => in_array($user->active_role, ['Admin', 'Dosen', 'Prodi', 'Staff'], true) && $user->can('guidance.update')],
        ]);
    }

    public function storeAppointment(AcademicGuidanceAppointmentRequest $request, NotificationService $notifications): RedirectResponse
    {
        $this->authorizeCreate($request); $user = $request->user(); $data = $request->validated();
        $student = $user->active_role === 'Mahasiswa' ? $user->student : Student::with('academicAdvisor.user')->findOrFail($data['student_id'] ?? 0);
        $this->authorizeStudent($user, $student); $lecturerId = $student->academic_advisor_id; abort_unless($lecturerId, 422, 'Mahasiswa belum memiliki dosen wali.');
        $appointment = AcademicGuidanceAppointment::create([...$data, 'student_id' => $student->id, 'lecturer_id' => $lecturerId, 'created_by' => $user->id, 'status' => 'pending']);
        if ($student->academicAdvisor?->user_id) $notifications->send($student->academicAdvisor->user_id, 'guidance_appointment', 'Permintaan bimbingan baru', $student->user?->name.' mengajukan jadwal bimbingan.', '/academic/guidance');
        $this->audit($request, 'appointment_created', 'academic_guidance_appointment', $appointment->id, ['student_id' => $student->id]);
        return back()->with('success', 'Jadwal bimbingan berhasil diajukan.');
    }

    public function decideAppointment(Request $request, AcademicGuidanceAppointment $appointment): RedirectResponse
    {
        $this->authorizeManage($request); $this->authorizeStudent($request->user(), $appointment->student); $data = $request->validate(['status' => ['required', Rule::in(['confirmed', 'completed', 'cancelled', 'no_show'])], 'lecturer_notes' => ['nullable', 'string', 'max:3000']]);
        $appointment->update([...$data, 'completed_at' => $data['status'] === 'completed' ? now() : null]); $this->audit($request, 'appointment_'.$data['status'], 'academic_guidance_appointment', $appointment->id, []);
        return back()->with('success', 'Status jadwal bimbingan diperbarui.');
    }

    public function storeNote(AcademicGuidanceNoteRequest $request, NotificationService $notifications): RedirectResponse
    {
        $this->authorizeManage($request); $data = $request->validated(); $student = Student::findOrFail($data['student_id']); $this->authorizeStudent($request->user(), $student); $lecturer = $request->user()->lecturer; abort_unless($lecturer || $request->user()->active_role === 'Admin', 403);
        $lecturerId = $lecturer?->id ?? $student->academic_advisor_id; abort_unless($lecturerId, 422, 'Mahasiswa belum memiliki dosen wali.');
        $note = AcademicGuidanceNote::create([...$data, 'lecturer_id' => $lecturerId, 'created_by' => $request->user()->id]);
        $notifications->student($student, 'guidance_note', 'Catatan bimbingan diperbarui', 'Dosen wali menambahkan catatan bimbingan baru.', '/academic/guidance'); $this->audit($request, 'guidance_note_created', 'academic_guidance_note', $note->id, ['student_id' => $student->id]);
        return back()->with('success', 'Catatan bimbingan tersimpan secara privat.');
    }

    public function decideWarning(EarlyWarningDecisionRequest $request, StudentEarlyWarning $warning): RedirectResponse
    {
        $this->authorizeManage($request); $this->authorizeStudent($request->user(), $warning->student); $data = $request->validated(); $warning->update([...$data, 'resolved_at' => $data['status'] === 'resolved' ? now() : null]); $this->audit($request, 'early_warning_'.$data['status'], 'student_early_warning', $warning->id, ['student_id' => $warning->student_id]);
        return back()->with('success', 'Tindak lanjut early warning diperbarui.');
    }

    public function storeAvailability(GuidanceAvailabilityRequest $request): RedirectResponse
    {
        $this->authorizeManage($request); abort_unless($request->user()->lecturer, 403); $slot = GuidanceAvailabilitySlot::create([...$request->validated(), 'lecturer_id' => $request->user()->lecturer->id]); $this->audit($request, 'availability_created', 'guidance_availability_slot', $slot->id, []); return back()->with('success', 'Slot ketersediaan bimbingan tersimpan.');
    }

    public function storeIntervention(StudentInterventionRequest $request, NotificationService $notifications): RedirectResponse
    {
        $this->authorizeManage($request); $data = $request->validated(); $student = Student::findOrFail($data['student_id']); $this->authorizeStudent($request->user(), $student); $plan = StudentInterventionPlan::create([...$data, 'assigned_lecturer_id' => $data['assigned_lecturer_id'] ?? $student->academic_advisor_id, 'created_by' => $request->user()->id]); $notifications->student($student, 'guidance_intervention', 'Rencana tindak lanjut baru', 'Rencana intervensi akademik telah dibuat untuk Anda.', '/academic/guidance'); $this->audit($request, 'intervention_created', 'student_intervention_plan', $plan->id, ['student_id' => $student->id]); return back()->with('success', 'Rencana intervensi berhasil dibuat.');
    }

    public function updateIntervention(Request $request, StudentInterventionPlan $plan): RedirectResponse
    {
        $this->authorizeManage($request); $this->authorizeStudent($request->user(), $plan->student); $data = $request->validate(['status' => ['required', 'in:open,in_progress,completed,cancelled'], 'outcome' => ['nullable', 'string', 'max:5000']]); $plan->update($data); $this->audit($request, 'intervention_'.$data['status'], 'student_intervention_plan', $plan->id, []); return back()->with('success', 'Status rencana intervensi diperbarui.');
    }

    private function refreshWarnings($students): void
    {
        foreach ($students as $student) {
            $gpa = $this->gpa($student); $attendance = $this->attendanceRate($student); $outstanding = (float) $student->billingItems()->whereIn('status', ['unpaid', 'partial'])->sum(DB::raw('amount - paid_amount'));
            $gpaThreshold = (float) config('siakad.guidance.low_gpa_threshold', 2.00); $attendanceThreshold = (float) config('siakad.guidance.low_attendance_threshold', 75);
            $checks = [['type' => 'low_gpa', 'severity' => $gpa !== null && $gpa < $gpaThreshold ? 'high' : 'medium', 'score' => $gpa, 'evidence' => ['gpa' => $gpa, 'threshold' => $gpaThreshold], 'active' => $gpa !== null && $gpa < $gpaThreshold], ['type' => 'low_attendance', 'severity' => 'high', 'score' => $attendance, 'evidence' => ['attendance_rate' => $attendance, 'threshold' => $attendanceThreshold], 'active' => $attendance !== null && $attendance < $attendanceThreshold], ['type' => 'outstanding_bill', 'severity' => 'medium', 'score' => $outstanding, 'evidence' => ['outstanding' => $outstanding], 'active' => $outstanding > 0], ['type' => 'inactive_status', 'severity' => 'high', 'score' => null, 'evidence' => ['status' => $student->status], 'active' => ! in_array($student->status, ['Aktif', 'active'], true)]];
            foreach ($checks as $check) if ($check['active']) StudentEarlyWarning::updateOrCreate(['student_id' => $student->id, 'warning_type' => $check['type'], 'status' => 'open'], ['assigned_lecturer_id' => $student->academic_advisor_id, 'severity' => $check['severity'], 'score' => $check['score'], 'evidence' => $check['evidence'], 'detected_at' => now()]);
        }
    }
    private function gpa(Student $student): ?float { $rows = CourseEnrollment::query()->whereHas('registration', fn ($q) => $q->where('student_id', $student->id)->where('status', 'approved'))->whereIn('grade_status', ['published', 'finalized'])->whereNotNull('letter_grade')->get(); if ($rows->isEmpty()) return null; $points = ['A' => 4, 'A-' => 3.7, 'B+' => 3.3, 'B' => 3, 'B-' => 2.7, 'C+' => 2.3, 'C' => 2, 'D' => 1, 'E' => 0]; $credits = $rows->sum('credits'); return $credits ? round($rows->sum(fn ($r) => ($points[strtoupper((string) $r->letter_grade)] ?? 0) * $r->credits) / $credits, 2) : null; }
    private function attendanceRate(Student $student): ?float { $rows = \App\Models\AttendanceRecord::query()->whereHas('enrollment.registration', fn ($q) => $q->where('student_id', $student->id)->where('status', 'approved'))->get(); if ($rows->isEmpty()) return null; return round($rows->whereIn('status', ['present', 'late'])->count() / $rows->count() * 100, 2); }
    private function authorizeView(Request $request): void { abort_unless(in_array($request->user()->active_role, ['Admin', 'Prodi', 'Dosen', 'Mahasiswa', 'Staff', 'Pimpinan'], true) && $request->user()->can('guidance.view'), 403); }
    private function authorizeCreate(Request $request): void { abort_unless($request->user()->can('guidance.create'), 403); }
    private function authorizeManage(Request $request): void { abort_unless($request->user()->can('guidance.update') && in_array($request->user()->active_role, ['Admin', 'Dosen', 'Prodi', 'Staff'], true), 403); }
    private function authorizeStudent($user, Student $student): void { abort_unless($user->active_role === 'Admin' || ($user->active_role === 'Mahasiswa' && (int) $user->student?->id === (int) $student->id) || ($user->active_role === 'Dosen' && (int) $user->lecturer?->id === (int) $student->academic_advisor_id) || in_array($user->active_role, ['Prodi', 'Staff', 'Pimpinan'], true), 403); }
    private function audit(Request $request, string $action, string $type, int $id, array $data): void { DB::table('audit_logs')->insert(['user_id' => $request->user()->id, 'module' => 'guidance', 'action' => $action, 'record_type' => $type, 'record_id' => (string) $id, 'new_data' => json_encode($data), 'ip_address' => $request->ip(), 'created_at' => now(), 'updated_at' => now()]); }
}
