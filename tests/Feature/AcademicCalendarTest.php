<?php

namespace Tests\Feature;

use App\Domain\Academic\ExamScheduleService;
use App\Models\AcademicCalendarEvent;
use App\Models\AcademicTerm;
use App\Models\Building;
use App\Models\Campus;
use App\Models\ClassGroup;
use App\Models\Course;
use App\Models\ExamSchedule;
use App\Models\Lecturer;
use App\Models\Program;
use App\Models\Room;
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
}
