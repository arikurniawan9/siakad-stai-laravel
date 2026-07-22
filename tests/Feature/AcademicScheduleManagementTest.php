<?php

namespace Tests\Feature;

use App\Models\AcademicTerm;
use App\Models\Building;
use App\Models\Campus;
use App\Models\ClassGroup;
use App\Models\Course;
use App\Models\Lecturer;
use App\Models\Program;
use App\Models\Room;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class AcademicScheduleManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_without_permissions_cannot_open_schedule_workspace(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('admin.academic-schedules'))
            ->assertForbidden();
    }

    public function test_authorized_user_can_open_schedule_workspace(): void
    {
        $user = $this->userWithPermissions('lecturers.view', 'schedules.view');
        $context = $this->context();

        $this->actingAs($user)
            ->get(route('admin.academic-schedules'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/AcademicSchedules')
                ->has('lecturers.data', 1)
                ->has('schedules.data', 0)
                ->has('courseOptions', 1)
                ->has('roomOptions', 1));
    }

    public function test_authorized_user_can_create_lecturer_and_mutation_is_audited(): void
    {
        $user = $this->userWithPermissions('lecturers.create');
        $program = $this->program();

        $this->actingAs($user)
            ->post(route('admin.lecturers.store'), [
                'program_id' => $program->id,
                'name' => 'Dr. Dosen Baru',
                'nidn' => '0123456789',
                'employee_number' => 'peg-001',
                'academic_title' => 'Lektor',
                'employment_status' => 'Tetap',
                'expertise' => 'Rekayasa Perangkat Lunak',
                'is_active' => true,
            ])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('lecturers', ['nidn' => '0123456789', 'employee_number' => 'PEG-001']);
        $this->assertDatabaseHas('audit_logs', ['module' => 'academic_schedules', 'action' => 'created', 'record_type' => 'lecturer']);
    }

    public function test_schedule_capacity_cannot_exceed_room_capacity(): void
    {
        $user = $this->userWithPermissions('schedules.create');
        $context = $this->context(roomCapacity: 30);

        $this->actingAs($user)
            ->post(route('admin.schedules.store'), $this->schedulePayload($context, ['capacity' => 31]))
            ->assertSessionHasErrors('capacity');

        $this->assertDatabaseCount('class_groups', 0);
    }

    public function test_authorized_user_can_create_schedule_and_mutation_is_audited(): void
    {
        $user = $this->userWithPermissions('schedules.create');
        $context = $this->context();

        $this->actingAs($user)
            ->post(route('admin.schedules.store'), $this->schedulePayload($context))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('class_groups', ['course_id' => $context['course']->id, 'lecturer_id' => $context['lecturer']->id, 'room_id' => $context['room']->id, 'day' => 'Senin']);
        $this->assertDatabaseHas('audit_logs', ['module' => 'academic_schedules', 'action' => 'created', 'record_type' => 'schedule']);
    }

    public function test_room_cannot_have_overlapping_schedules_but_adjacent_slot_is_allowed(): void
    {
        $user = $this->userWithPermissions('schedules.create');
        $context = $this->context();
        $otherLecturer = Lecturer::create(['program_id' => $context['program']->id, 'name' => 'Dosen Dua', 'nidn' => '2222', 'employment_status' => 'Tetap']);
        $this->actingAs($user)->post(route('admin.schedules.store'), $this->schedulePayload($context));

        $this->actingAs($user)
            ->post(route('admin.schedules.store'), $this->schedulePayload($context, ['name' => 'B', 'lecturer_id' => $otherLecturer->id, 'starts_at' => '09:00', 'ends_at' => '10:00']))
            ->assertSessionHasErrors('room_id');

        $this->actingAs($user)
            ->post(route('admin.schedules.store'), $this->schedulePayload($context, ['name' => 'C', 'lecturer_id' => $otherLecturer->id, 'starts_at' => '09:40', 'ends_at' => '11:20']))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseCount('class_groups', 2);
    }

    public function test_lecturer_cannot_teach_overlapping_schedules_in_different_rooms(): void
    {
        $user = $this->userWithPermissions('schedules.create');
        $context = $this->context();
        $otherRoom = Room::create(['building_id' => $context['building']->id, 'name' => 'Ruang 102', 'code' => 'R-102', 'floor' => 1, 'type' => 'Kelas', 'capacity' => 40]);
        $this->actingAs($user)->post(route('admin.schedules.store'), $this->schedulePayload($context));

        $this->actingAs($user)
            ->post(route('admin.schedules.store'), $this->schedulePayload($context, ['name' => 'B', 'room_id' => $otherRoom->id, 'starts_at' => '09:00', 'ends_at' => '10:00']))
            ->assertSessionHasErrors('lecturer_id');

        $this->assertDatabaseCount('class_groups', 1);
    }

    public function test_capacity_cannot_be_reduced_below_enrolled_students(): void
    {
        $user = $this->userWithPermissions('schedules.update');
        $context = $this->context();
        $schedule = ClassGroup::create([...$this->modelSchedulePayload($context), 'enrolled_count' => 20]);

        $this->actingAs($user)
            ->patch(route('admin.schedules.update', $schedule), $this->schedulePayload($context, ['capacity' => 19]))
            ->assertSessionHasErrors('capacity');

        $this->assertSame(30, $schedule->fresh()->capacity);
    }

    public function test_lecturer_and_room_with_schedule_cannot_be_archived(): void
    {
        $user = $this->userWithPermissions('lecturers.delete', 'rooms.delete');
        $context = $this->context();
        ClassGroup::create($this->modelSchedulePayload($context));

        $this->actingAs($user)
            ->delete(route('admin.lecturers.destroy', $context['lecturer']))
            ->assertSessionHasErrors('lecturer');
        $this->actingAs($user)
            ->delete(route('admin.facilities.destroy', ['resource' => 'rooms', 'id' => $context['room']->id]))
            ->assertSessionHasErrors('room');

        $this->assertNotSoftDeleted($context['lecturer']);
        $this->assertNotSoftDeleted($context['room']);
    }

    public function test_archived_schedule_cannot_be_restored_when_slot_has_been_reused(): void
    {
        $user = $this->userWithPermissions('schedules.create', 'schedules.delete', 'schedules.update');
        $context = $this->context();
        $schedule = ClassGroup::create($this->modelSchedulePayload($context));
        $this->actingAs($user)->delete(route('admin.schedules.destroy', $schedule));
        $this->assertSoftDeleted($schedule);

        $this->actingAs($user)->post(route('admin.schedules.store'), $this->schedulePayload($context, ['name' => 'B']))->assertSessionHasNoErrors();
        $this->actingAs($user)
            ->patch(route('admin.schedules.restore', $schedule->id))
            ->assertSessionHasErrors(['room_id', 'lecturer_id']);

        $this->assertSoftDeleted($schedule);
    }

    private function userWithPermissions(string ...$permissions): User
    {
        $user = User::factory()->create();
        foreach ($permissions as $permission) Permission::findOrCreate($permission, 'web');
        $user->givePermissionTo($permissions);

        return $user;
    }

    private function program(): Program
    {
        return Program::create(['name' => 'Teknik Informatika', 'code' => 'TI', 'degree' => 'S1', 'is_active' => true]);
    }

    private function context(int $roomCapacity = 40): array
    {
        $program = $this->program();
        $course = Course::create(['program_id' => $program->id, 'name' => 'Algoritma', 'code' => 'TI101', 'credits' => 3, 'type' => 'Wajib', 'is_active' => true]);
        $term = AcademicTerm::create(['name' => 'Ganjil 2026', 'code' => '2026-GANJIL', 'semester' => 'Ganjil', 'is_active' => true]);
        $lecturer = Lecturer::create(['program_id' => $program->id, 'name' => 'Dosen Satu', 'nidn' => '1111', 'employment_status' => 'Tetap']);
        $campus = Campus::create(['name' => 'Kampus Utama', 'code' => 'KU']);
        $building = Building::create(['campus_id' => $campus->id, 'name' => 'Gedung A', 'code' => 'GA', 'floor_count' => 2]);
        $room = Room::create(['building_id' => $building->id, 'name' => 'Ruang 101', 'code' => 'R-101', 'floor' => 1, 'type' => 'Kelas', 'capacity' => $roomCapacity]);

        return compact('program', 'course', 'term', 'lecturer', 'campus', 'building', 'room');
    }

    private function schedulePayload(array $context, array $overrides = []): array
    {
        return [...$this->modelSchedulePayload($context), ...$overrides];
    }

    private function modelSchedulePayload(array $context): array
    {
        return [
            'academic_term_id' => $context['term']->id,
            'course_id' => $context['course']->id,
            'lecturer_id' => $context['lecturer']->id,
            'room_id' => $context['room']->id,
            'name' => 'A',
            'capacity' => 30,
            'day' => 'Senin',
            'starts_at' => '08:00',
            'ends_at' => '09:40',
            'is_active' => true,
        ];
    }
}
