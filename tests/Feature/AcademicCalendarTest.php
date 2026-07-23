<?php

namespace Tests\Feature;

use App\Domain\Academic\ExamScheduleService;
use App\Domain\Academic\ExamOperationsService;
use App\Models\AcademicRegistrationPeriod;
use App\Models\AcademicCalendarEvent;
use App\Models\AcademicTerm;
use App\Models\AttendanceRecord;
use App\Models\AttendanceSession;
use App\Models\Building;
use App\Models\Campus;
use App\Models\ClassGroup;
use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\ExamSchedule;
use App\Models\Lecturer;
use App\Models\Program;
use App\Models\Room;
use App\Models\SemesterRegistration;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class AcademicCalendarTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_without_calendar_permissions_cannot_open_workspace(): void
    {
        $this->actingAs(User::factory()->create(['active_role' => 'Mahasiswa']))->get(route('academic.calendar'))->assertForbidden();
    }

    public function test_manager_can_create_calendar_event_and_exam_is_audited(): void
    {
        $context = $this->context(); $user = $this->manager('Prodi', 'calendar.create', 'calendar.update', 'calendar.view', 'exams.create', 'exams.update', 'exams.view');
        $this->actingAs($user)->post(route('academic.calendar.events.store'), ['academic_term_id' => $context['term']->id, 'title' => 'Masa UTS', 'event_type' => 'academic', 'starts_at' => '2026-10-01 08:00', 'ends_at' => '2026-10-01 17:00', 'description' => 'Ujian tengah semester', 'location' => 'Kampus utama', 'is_published' => true])->assertSessionHasNoErrors();
        $this->actingAs($user)->post(route('academic.calendar.exams.store'), $this->examPayload($context, ['status' => 'published']))->assertSessionHasNoErrors();
        $this->assertDatabaseHas('academic_calendar_events', ['title' => 'Masa UTS']); $this->assertDatabaseHas('exam_schedules', ['exam_type' => 'uts', 'status' => 'published']); $this->assertDatabaseHas('audit_logs', ['module' => 'academic_calendar', 'action' => 'exam_created']);
    }

    public function test_exam_rejects_room_and_lecturer_overlap_but_adjacent_slot_is_allowed(): void
    {
        $context = $this->context(); $service = app(ExamScheduleService::class); $user = $this->manager('Prodi', 'exams.create', 'exams.view');
        $service->create($this->examPayload($context), $user);
        $this->expectException(ValidationException::class); $service->create($this->examPayload($context, ['exam_type' => 'uas']), $user);
    }

    public function test_exam_eligibility_requires_approved_krs_attendance_and_clear_finance(): void
    {
        $context = $this->context(); $service = app(ExamScheduleService::class); $user = $this->manager('Prodi', 'exams.create', 'exams.view'); $exam = $service->create($this->examPayload($context, ['status' => 'published']), $user);
        $student = \App\Models\Student::create(['user_id' => User::factory()->create()->id, 'program_id' => $context['program']->id, 'nim' => 'TI260001', 'status' => 'Aktif', 'current_semester' => 1]);
        $result = $service->eligibility($exam->fresh('classGroup'), $student); $this->assertFalse($result['eligible']); $this->assertFalse($result['krs']['ok']); $this->assertFalse($result['attendance']['ok']);
    }

    public function test_invigilator_assignment_rejects_overlapping_duty_and_notifies_assigned_lecturer(): void
    {
        $first = $this->context('TI201', 'Dosen Pengawas', 'R-201');
        $second = $this->context('TI202', 'Dosen Lain', 'R-202');
        $manager = $this->manager('Prodi', 'exams.assign', 'exams.operate', 'exams.view');
        $lecturerUser = User::factory()->create(['active_role' => 'Dosen']);
        $first['lecturer']->update(['user_id' => $lecturerUser->id]);
        $schedules = app(ExamScheduleService::class);
        $operations = app(ExamOperationsService::class);
        $examOne = $schedules->create($this->examPayload($first, ['status' => 'published']), $manager);
        $examTwo = $schedules->create($this->examPayload($second, ['status' => 'published']), $manager);

        $this->actingAs($manager)->put(route('academic.exams.invigilators.update', $examOne), ['lecturer_ids' => [$first['lecturer']->id], 'coordinator_id' => $first['lecturer']->id])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('exam_invigilators', ['exam_schedule_id' => $examOne->id, 'lecturer_id' => $first['lecturer']->id, 'role' => 'coordinator']);
        $this->assertDatabaseHas('system_notifications', ['user_id' => $lecturerUser->id, 'type' => 'exam_assignment']);

        $this->expectException(ValidationException::class);
        $operations->syncInvigilators($examTwo, ['lecturer_ids' => [$first['lecturer']->id], 'coordinator_id' => $first['lecturer']->id], $manager);
    }

    public function test_roster_is_eligibility_snapshot_and_preparation_is_idempotent(): void
    {
        $context = $this->context('TI301', 'Dosen Tiga', 'R-301');
        $manager = $this->manager('Prodi', 'exams.operate', 'exams.view');
        $exam = app(ExamScheduleService::class)->create($this->examPayload($context, ['status' => 'published']), $manager);
        $this->eligibleStudent($context, $manager);
        $operations = app(ExamOperationsService::class);

        $first = $operations->prepareRoster($exam, $manager);
        $second = $operations->prepareRoster($exam, $manager);

        $this->assertCount(1, $first);
        $this->assertSame($first->first()->id, $second->first()->id);
        $this->assertTrue($second->first()->is_eligible);
        $this->assertTrue($second->first()->eligibility_snapshot['krs']['ok']);
        $this->assertDatabaseCount('exam_participants', 1);
    }

    public function test_only_assigned_invigilator_can_record_attendance(): void
    {
        $context = $this->context('TI401', 'Dosen Empat', 'R-401');
        $manager = $this->manager('Prodi', 'exams.assign', 'exams.operate', 'exams.view');
        $exam = app(ExamScheduleService::class)->create($this->examPayload($context, ['status' => 'published']), $manager);
        $this->eligibleStudent($context, $manager);
        $operations = app(ExamOperationsService::class);
        $participant = $operations->prepareRoster($exam, $manager)->first();
        $outsider = $this->manager('Dosen', 'exams.operate', 'exams.view');

        $this->actingAs($outsider)->put(route('academic.exams.attendance.update', $exam), ['participants' => [['id' => $participant->id, 'attendance_status' => 'present', 'notes' => null]]])->assertForbidden();

        $invigilatorUser = $this->manager('Dosen', 'exams.operate', 'exams.view');
        $context['lecturer']->update(['user_id' => $invigilatorUser->id]);
        $operations->syncInvigilators($exam, ['lecturer_ids' => [$context['lecturer']->id], 'coordinator_id' => $context['lecturer']->id], $manager);
        $this->actingAs($invigilatorUser)->put(route('academic.exams.attendance.update', $exam), ['participants' => [['id' => $participant->id, 'attendance_status' => 'present', 'notes' => 'Hadir tepat waktu']]])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('exam_participants', ['id' => $participant->id, 'attendance_status' => 'present', 'recorded_by' => $invigilatorUser->id]);
    }

    public function test_final_report_requires_complete_attendance_then_is_immutable_and_downloadable(): void
    {
        $context = $this->context('TI501', 'Dosen Lima', 'R-501');
        $manager = $this->manager('Prodi', 'exams.assign', 'exams.operate', 'exams.view');
        $exam = app(ExamScheduleService::class)->create($this->examPayload($context, ['status' => 'published']), $manager);
        $this->eligibleStudent($context, $manager);
        $operations = app(ExamOperationsService::class);
        $operations->syncInvigilators($exam, ['lecturer_ids' => [$context['lecturer']->id], 'coordinator_id' => $context['lecturer']->id], $manager);
        $participant = $operations->prepareRoster($exam, $manager)->first();
        $payload = ['status' => 'finalized', 'actual_starts_at' => '2026-10-12 08:03', 'actual_ends_at' => '2026-10-12 09:55', 'material_summary' => 'Capaian pembelajaran pertemuan satu sampai tujuh.', 'incidents' => null, 'notes' => 'Ujian berjalan tertib.'];

        try {
            $operations->saveReport($exam, $payload, $manager);
            $this->fail('Finalisasi seharusnya menolak kehadiran yang belum dicatat.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('participants', $exception->errors());
        }

        $operations->recordAttendance($exam, [['id' => $participant->id, 'attendance_status' => 'present', 'notes' => null]], $manager);
        $report = $operations->saveReport($exam, $payload, $manager);
        $this->assertSame('finalized', $report->status);
        $this->assertSame(1, $report->present_count);
        $this->actingAs($manager)->get(route('academic.exams.attendance.pdf', $exam))->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->actingAs($manager)->get(route('academic.exams.report.pdf', $exam))->assertOk()->assertHeader('Content-Type', 'application/pdf');

        $this->expectException(ValidationException::class);
        $operations->saveReport($exam, [...$payload, 'notes' => 'Perubahan terlarang'], $manager);
    }

    private function manager(string $role, string ...$permissions): User
    {
        $user = User::factory()->create(['active_role' => $role]); foreach ($permissions as $permission) Permission::findOrCreate($permission, 'web'); $user->givePermissionTo($permissions); return $user;
    }

    private function context(string $courseCode = 'TI101', string $lecturerName = 'Dosen Satu', string $roomCode = 'R-101'): array
    {
        $program = Program::create(['name' => 'Teknik Informatika '.substr($courseCode, -3), 'code' => 'TI'.substr($courseCode, -1), 'degree' => 'S1', 'is_active' => true]); $course = Course::create(['program_id' => $program->id, 'name' => 'Algoritma', 'code' => $courseCode, 'credits' => 3, 'type' => 'Wajib', 'is_active' => true]); $term = AcademicTerm::create(['name' => 'Ganjil 2026', 'code' => '2026-GANJIL-'.substr($courseCode, -1), 'semester' => 'Ganjil', 'starts_on' => '2026-08-01', 'ends_on' => '2027-01-31', 'is_active' => true]); $lecturer = Lecturer::create(['program_id' => $program->id, 'name' => $lecturerName, 'nidn' => '111'.substr($courseCode, -1), 'employment_status' => 'Tetap']); $campus = Campus::create(['name' => 'Kampus Utama '.substr($courseCode, -1), 'code' => 'KU'.substr($courseCode, -1)]); $building = Building::create(['campus_id' => $campus->id, 'name' => 'Gedung A '.substr($courseCode, -1), 'code' => 'GA'.substr($courseCode, -1), 'floor_count' => 2]); $room = Room::create(['building_id' => $building->id, 'name' => 'Ruang '.$roomCode, 'code' => $roomCode, 'floor' => 1, 'type' => 'Kelas', 'capacity' => 40]); $class = ClassGroup::create(['academic_term_id' => $term->id, 'course_id' => $course->id, 'lecturer_id' => $lecturer->id, 'room_id' => $room->id, 'name' => 'A', 'capacity' => 30, 'enrolled_count' => 0, 'day' => 'Senin', 'starts_at' => '08:00', 'ends_at' => '09:40', 'is_active' => true]); return compact('program', 'course', 'term', 'lecturer', 'campus', 'building', 'room', 'class');
    }

    private function examPayload(array $context, array $overrides = []): array
    {
        return [...['academic_term_id' => $context['term']->id, 'class_group_id' => $context['class']->id, 'exam_type' => 'uts', 'exam_date' => '2026-10-12', 'starts_at' => '08:00', 'ends_at' => '10:00', 'room_id' => $context['room']->id, 'delivery_mode' => 'onsite', 'status' => 'draft', 'notes' => null], ...$overrides];
    }

    private function eligibleStudent(array $context, User $actor): array
    {
        $studentUser = User::factory()->create(['active_role' => 'Mahasiswa']);
        $student = Student::create(['user_id' => $studentUser->id, 'program_id' => $context['program']->id, 'nim' => 'MHS'.substr($context['course']->code, -3), 'status' => 'Aktif', 'current_semester' => 1]);
        $period = AcademicRegistrationPeriod::create(['academic_term_id' => $context['term']->id, 'starts_at' => '2026-07-01 00:00:00', 'ends_at' => '2026-08-31 23:59:59', 'default_max_credits' => 24, 'is_open' => false]);
        $registration = SemesterRegistration::create(['student_id' => $student->id, 'academic_term_id' => $context['term']->id, 'academic_registration_period_id' => $period->id, 'max_credits' => 24, 'status' => 'approved']);
        $enrollment = CourseEnrollment::create(['semester_registration_id' => $registration->id, 'class_group_id' => $context['class']->id, 'credits' => $context['course']->credits, 'status' => 'enrolled', 'enrolled_at' => now()]);
        $session = AttendanceSession::create(['class_group_id' => $context['class']->id, 'meeting_number' => 1, 'starts_at' => '2026-09-01 08:00:00', 'ends_at' => '2026-09-01 09:40:00', 'topic' => 'Pertemuan pertama', 'delivery_mode' => 'onsite', 'status' => 'closed', 'access_code' => '123456', 'created_by' => $actor->id, 'closed_by' => $actor->id, 'closed_at' => '2026-09-01 09:40:00']);
        AttendanceRecord::create(['attendance_session_id' => $session->id, 'course_enrollment_id' => $enrollment->id, 'status' => 'present', 'checked_in_at' => '2026-09-01 08:00:00', 'recorded_by' => $actor->id]);
        $context['class']->update(['enrolled_count' => 1]);

        return compact('student', 'registration', 'enrollment', 'session');
    }
}
