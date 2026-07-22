<?php

namespace Tests\Feature;

use App\Models\AcademicGuidanceAppointment;
use App\Models\GuidanceAvailabilitySlot;
use App\Models\Lecturer;
use App\Models\Program;
use App\Models\Student;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class AcademicGuidanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_student_can_request_inside_advisor_slot_and_conflict_is_rejected(): void
    {
        $context = $this->context(); $start = now()->addDays(2)->setTime(10, 0); $end = $start->copy()->addHour();
        GuidanceAvailabilitySlot::create(['lecturer_id' => $context['advisor']->id, 'weekday' => $start->dayOfWeekIso, 'starts_at' => '09:00', 'ends_at' => '12:00', 'mode' => 'online', 'is_active' => true]);
        $payload = ['student_id' => $context['student']->id, 'starts_at' => $start->toDateTimeString(), 'ends_at' => $end->toDateTimeString(), 'mode' => 'online', 'agenda' => 'Evaluasi KRS'];
        $this->actingAs($context['studentUser'])->post(route('academic.guidance.appointments.store'), $payload)->assertSessionHasNoErrors();
        $this->assertDatabaseCount('academic_guidance_appointments', 1);
        $this->actingAs($context['studentUser'])->post(route('academic.guidance.appointments.store'), $payload)->assertSessionHasErrors('starts_at');
    }

    public function test_student_cannot_schedule_outside_existing_advisor_slots(): void
    {
        $context = $this->context(); $start = now()->addDays(3)->setTime(15, 0); $end = $start->copy()->addHour();
        GuidanceAvailabilitySlot::create(['lecturer_id' => $context['advisor']->id, 'weekday' => $start->dayOfWeekIso, 'starts_at' => '09:00', 'ends_at' => '12:00', 'mode' => 'online', 'is_active' => true]);
        $this->actingAs($context['studentUser'])->post(route('academic.guidance.appointments.store'), ['student_id' => $context['student']->id, 'starts_at' => $start->toDateTimeString(), 'ends_at' => $end->toDateTimeString(), 'mode' => 'online', 'agenda' => 'Konsultasi'])->assertSessionHasErrors('starts_at');
    }

    public function test_other_lecturer_cannot_open_advisor_guidance_workspace(): void
    {
        $context = $this->context(); $otherUser = $this->roleUser('Dosen', 'guidance.view', 'guidance.update'); Lecturer::create(['user_id' => $otherUser->id, 'program_id' => $context['program']->id, 'name' => 'Dosen Lain', 'nidn' => 'NIDN-999', 'employment_status' => 'Tetap']);
        $this->actingAs($otherUser)->get(route('academic.guidance'))->assertOk()->assertInertia(fn ($page) => $page->where('students', []));
    }

    private function context(): array
    {
        $program = Program::create(['name' => 'Teknik Informatika', 'code' => 'TI', 'degree' => 'S1', 'is_active' => true]); $advisorUser = $this->roleUser('Dosen', 'guidance.view', 'guidance.create', 'guidance.update'); $advisor = Lecturer::create(['user_id' => $advisorUser->id, 'program_id' => $program->id, 'name' => 'Dosen PA', 'nidn' => 'NIDN-001', 'employment_status' => 'Tetap']); $studentUser = $this->roleUser('Mahasiswa', 'guidance.view', 'guidance.create'); $student = Student::create(['user_id' => $studentUser->id, 'program_id' => $program->id, 'academic_advisor_id' => $advisor->id, 'nim' => '22001', 'status' => 'Aktif', 'current_semester' => 3]); return compact('program', 'advisor', 'advisorUser', 'student', 'studentUser');
    }
    private function roleUser(string $roleName, string ...$permissions): \App\Models\User { $role = Role::findOrCreate($roleName, 'web'); $user = \App\Models\User::factory()->create(['active_role' => $roleName]); $user->assignRole($role); foreach ($permissions as $permission) Permission::findOrCreate($permission, 'web'); $user->givePermissionTo($permissions); return $user; }
}
