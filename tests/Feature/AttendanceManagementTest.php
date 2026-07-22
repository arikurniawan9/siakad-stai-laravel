<?php

namespace Tests\Feature;

use App\Models\AcademicRegistrationPeriod;
use App\Models\AcademicTerm;
use App\Models\AttendanceRecord;
use App\Models\AttendanceSession;
use App\Models\ClassGroup;
use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\Lecturer;
use App\Models\Program;
use App\Models\SemesterRegistration;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

final class AttendanceManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_lecturer_only_sees_and_manages_assigned_class(): void
    {
        $context = $this->context();
        $outsider = $this->permissionUser('Dosen', 'attendance.view', 'attendance.update');
        Lecturer::create(['user_id' => $outsider->id, 'program_id' => $context['program']->id, 'name' => 'Dosen Lain', 'nidn' => 'NIDN-002', 'employment_status' => 'Tetap']);

        $this->actingAs($outsider)->get(route('academic.attendance', ['selected' => $context['class']->id]))
            ->assertOk()->assertInertia(fn (Assert $page) => $page->component('Academic/Attendance')->where('selectedClass', null)->where('classGroups.total', 0));
        $this->actingAs($context['lecturerUser'])->get(route('academic.attendance', ['selected' => $context['class']->id]))
            ->assertOk()->assertInertia(fn (Assert $page) => $page->where('selectedClass.id', $context['class']->id)->where('abilities.manage', true));
    }

    public function test_session_snapshots_participants_and_encrypts_access_code_at_rest(): void
    {
        $context = $this->context();
        $this->actingAs($context['lecturerUser'])->post(route('academic.attendance.sessions.store', $context['class']), $this->sessionPayload('482731'))->assertSessionHasNoErrors();

        $session = AttendanceSession::query()->sole();
        $this->assertSame('482731', $session->access_code);
        $this->assertNotSame('482731', DB::table('attendance_sessions')->where('id', $session->id)->value('access_code'));
        $this->assertDatabaseCount('attendance_records', 2);
        $this->assertDatabaseHas('audit_logs', ['module' => 'attendance', 'action' => 'session_created']);
    }

    public function test_student_can_check_in_once_and_never_receives_manager_access_code(): void
    {
        $context = $this->context();
        $this->actingAs($context['lecturerUser'])->post(route('academic.attendance.sessions.store', $context['class']), $this->sessionPayload('482731'))->assertSessionHasNoErrors();
        $session = AttendanceSession::query()->sole();
        $this->post(route('academic.attendance.sessions.transition', [$context['class'], $session]), ['status' => 'open'])->assertSessionHasNoErrors();

        $this->actingAs($context['studentUsers'][0])->get(route('academic.attendance', ['selected' => $context['class']->id]))
            ->assertOk()->assertInertia(fn (Assert $page) => $page
                ->where('mode', 'student')->missing('selectedClass.attendance_sessions.0.manager_access_code')
                ->missing('selectedClass.attendance_sessions.0.access_code'));
        $this->post(route('academic.attendance.check-in', [$context['class'], $session]), ['code' => '000000'])->assertSessionHasErrors('code');
        $this->post(route('academic.attendance.check-in', [$context['class'], $session]), ['code' => '482731'])->assertSessionHasNoErrors();
        $record = AttendanceRecord::query()->where('course_enrollment_id', $context['enrollments'][0]->id)->sole();
        $this->assertSame('present', $record->status);
        $this->post(route('academic.attendance.check-in', [$context['class'], $session]), ['code' => '482731'])->assertSessionHasErrors('code');
    }

    public function test_closing_session_marks_unrecorded_students_absent_and_locks_changes(): void
    {
        $context = $this->context();
        $this->actingAs($context['lecturerUser'])->post(route('academic.attendance.sessions.store', $context['class']), $this->sessionPayload('482731'))->assertSessionHasNoErrors();
        $session = AttendanceSession::query()->sole();
        $this->post(route('academic.attendance.sessions.transition', [$context['class'], $session]), ['status' => 'open'])->assertSessionHasNoErrors();
        $record = AttendanceRecord::query()->where('course_enrollment_id', $context['enrollments'][0]->id)->sole();
        $this->put(route('academic.attendance.records.update', [$context['class'], $session]), ['records' => [['id' => $record->id, 'status' => 'sick', 'notes' => 'Surat dokter']]])->assertSessionHasNoErrors();
        $this->post(route('academic.attendance.sessions.transition', [$context['class'], $session]), ['status' => 'closed'])->assertSessionHasNoErrors();

        $this->assertSame('sick', $record->fresh()->status);
        $this->assertDatabaseHas('attendance_records', ['course_enrollment_id' => $context['enrollments'][1]->id, 'status' => 'absent']);
        $this->put(route('academic.attendance.records.update', [$context['class'], $session]), ['records' => [['id' => $record->id, 'status' => 'present']]])->assertSessionHasErrors('session');
    }

    public function test_student_cannot_access_another_class_or_manager_actions(): void
    {
        $context = $this->context();
        $otherCourse = Course::create(['program_id' => $context['program']->id, 'name' => 'Basis Data', 'code' => 'TI102', 'credits' => 3, 'type' => 'Wajib', 'is_active' => true]);
        $otherClass = ClassGroup::create(['academic_term_id' => $context['term']->id, 'course_id' => $otherCourse->id, 'lecturer_id' => $context['lecturer']->id, 'name' => 'B', 'capacity' => 30, 'day' => 'Selasa', 'starts_at' => '08:00', 'ends_at' => '09:40', 'is_active' => true]);

        $this->actingAs($context['studentUsers'][0])->get(route('academic.attendance', ['selected' => $otherClass->id]))
            ->assertOk()->assertInertia(fn (Assert $page) => $page->where('selectedClass', null));
        $this->post(route('academic.attendance.sessions.store', $context['class']), $this->sessionPayload('123456'))->assertForbidden();
    }

    private function context(): array
    {
        $program = Program::create(['name' => 'Teknik Informatika', 'code' => 'TI', 'degree' => 'S1', 'is_active' => true]);
        $term = AcademicTerm::create(['name' => 'Ganjil 2026', 'code' => '2026-GANJIL', 'semester' => 'Ganjil', 'starts_on' => now()->subMonth()->toDateString(), 'ends_on' => now()->addMonths(4)->toDateString(), 'is_active' => true]);
        $period = AcademicRegistrationPeriod::create(['academic_term_id' => $term->id, 'starts_at' => now()->subMonth(), 'ends_at' => now()->addMonth(), 'default_max_credits' => 24, 'is_open' => true]);
        $course = Course::create(['program_id' => $program->id, 'name' => 'Algoritma', 'code' => 'TI101', 'credits' => 3, 'type' => 'Wajib', 'is_active' => true]);
        $lecturerUser = $this->permissionUser('Dosen', 'attendance.view', 'attendance.create', 'attendance.update', 'attendance.delete');
        $lecturer = Lecturer::create(['user_id' => $lecturerUser->id, 'program_id' => $program->id, 'name' => 'Dosen Algoritma', 'nidn' => 'NIDN-001', 'employment_status' => 'Tetap']);
        $class = ClassGroup::create(['academic_term_id' => $term->id, 'course_id' => $course->id, 'lecturer_id' => $lecturer->id, 'name' => 'A', 'capacity' => 30, 'enrolled_count' => 2, 'day' => 'Senin', 'starts_at' => '08:00', 'ends_at' => '09:40', 'is_active' => true]);
        $studentUsers = []; $registrations = []; $enrollments = [];
        foreach (['22001', '22002'] as $nim) {
            $studentUser = $this->permissionUser('Mahasiswa', 'attendance.view', 'attendance.create');
            $student = Student::create(['user_id' => $studentUser->id, 'program_id' => $program->id, 'nim' => $nim, 'status' => 'Aktif', 'current_semester' => 1]);
            $registration = SemesterRegistration::create(['student_id' => $student->id, 'academic_term_id' => $term->id, 'academic_registration_period_id' => $period->id, 'max_credits' => 24, 'status' => 'approved']);
            $enrollment = CourseEnrollment::create(['semester_registration_id' => $registration->id, 'class_group_id' => $class->id, 'credits' => 3, 'status' => 'enrolled']);
            $studentUsers[] = $studentUser; $registrations[] = $registration; $enrollments[] = $enrollment;
        }

        return compact('program', 'term', 'course', 'lecturerUser', 'lecturer', 'class', 'studentUsers', 'registrations', 'enrollments');
    }

    private function sessionPayload(string $code): array
    {
        return ['meeting_number' => 1, 'starts_at' => now()->subMinutes(5)->format('Y-m-d H:i:s'), 'ends_at' => now()->addHour()->format('Y-m-d H:i:s'), 'topic' => 'Pengenalan algoritma', 'delivery_mode' => 'onsite', 'notes' => 'Pertemuan perdana', 'access_code' => $code];
    }

    private function permissionUser(string $activeRole, string ...$permissions): User
    {
        $user = User::factory()->create(['active_role' => $activeRole]);
        foreach ($permissions as $permission) Permission::findOrCreate($permission, 'web');
        $user->givePermissionTo($permissions);

        return $user;
    }
}
