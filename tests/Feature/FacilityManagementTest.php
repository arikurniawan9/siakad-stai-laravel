<?php

namespace Tests\Feature;

use App\Models\Building;
use App\Models\Campus;
use App\Models\Room;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class FacilityManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_without_facility_permissions_cannot_open_facility_page(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('admin.facilities'))
            ->assertForbidden();
    }

    public function test_authorized_user_can_create_a_building_and_mutation_is_audited(): void
    {
        $user = $this->userWithPermissions('buildings.create');
        $campus = Campus::create(['name' => 'Kampus Utama', 'code' => 'KU']);

        $this->actingAs($user)
            ->post(route('admin.facilities.store', 'buildings'), [
                'campus_id' => $campus->id,
                'name' => 'Gedung Rektorat',
                'code' => 'gdr',
                'floor_count' => 3,
                'description' => 'Gedung administrasi',
                'is_active' => true,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('buildings', [
            'campus_id' => $campus->id,
            'name' => 'Gedung Rektorat',
            'code' => 'GDR',
            'floor_count' => 3,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $user->id,
            'module' => 'facilities',
            'action' => 'created',
            'record_type' => 'buildings',
        ]);
    }

    public function test_building_code_must_be_unique_inside_the_same_campus(): void
    {
        $user = $this->userWithPermissions('buildings.create');
        $firstCampus = Campus::create(['name' => 'Kampus Utama', 'code' => 'KU']);
        $secondCampus = Campus::create(['name' => 'Kampus Dua', 'code' => 'KD']);
        Building::create(['campus_id' => $firstCampus->id, 'name' => 'Gedung A', 'code' => 'GA', 'floor_count' => 2]);

        $payload = ['name' => 'Gedung Baru', 'code' => 'GA', 'floor_count' => 1, 'is_active' => true];
        $this->actingAs($user)
            ->post(route('admin.facilities.store', 'buildings'), ['campus_id' => $firstCampus->id, ...$payload])
            ->assertSessionHasErrors('code');

        $this->actingAs($user)
            ->post(route('admin.facilities.store', 'buildings'), ['campus_id' => $secondCampus->id, ...$payload])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseCount('buildings', 2);
    }

    public function test_room_floor_cannot_exceed_building_floor_count(): void
    {
        $user = $this->userWithPermissions('rooms.create');
        $building = $this->building(floors: 2);

        $this->actingAs($user)
            ->post(route('admin.facilities.store', 'rooms'), [
                'building_id' => $building->id,
                'name' => 'Laboratorium Komputer',
                'code' => 'LAB-01',
                'floor' => 3,
                'type' => 'Laboratorium',
                'capacity' => 30,
                'facilities' => 'Proyektor, AC',
                'is_active' => true,
            ])
            ->assertSessionHasErrors('floor');

        $this->assertDatabaseCount('rooms', 0);
    }

    public function test_authorized_user_can_create_room_with_normalized_facilities(): void
    {
        $user = $this->userWithPermissions('rooms.create');
        $building = $this->building(floors: 2);

        $this->actingAs($user)
            ->post(route('admin.facilities.store', 'rooms'), [
                'building_id' => $building->id,
                'name' => 'Ruang Kuliah 201',
                'code' => 'r-201',
                'floor' => 2,
                'type' => 'Kelas',
                'capacity' => 40,
                'facilities' => 'Proyektor, AC, Wi-Fi',
                'is_active' => true,
            ])
            ->assertSessionHasNoErrors();

        $room = Room::query()->sole();
        $this->assertSame('R-201', $room->code);
        $this->assertSame(['Proyektor', 'AC', 'Wi-Fi'], $room->facilities);
    }

    public function test_room_cannot_be_created_inside_an_archived_building(): void
    {
        $user = $this->userWithPermissions('rooms.create');
        $building = $this->building();
        $building->delete();

        $this->actingAs($user)
            ->post(route('admin.facilities.store', 'rooms'), [
                'building_id' => $building->id,
                'name' => 'Ruang 101',
                'code' => 'R-101',
                'floor' => 1,
                'type' => 'Kelas',
                'capacity' => 30,
                'is_active' => true,
            ])
            ->assertSessionHasErrors('building_id');

        $this->assertDatabaseCount('rooms', 0);
    }

    public function test_floor_count_cannot_be_lowered_below_an_existing_room(): void
    {
        $user = $this->userWithPermissions('buildings.update');
        $building = $this->building(floors: 3);
        Room::create([
            'building_id' => $building->id,
            'name' => 'Ruang 301',
            'code' => 'R-301',
            'floor' => 3,
            'type' => 'Kelas',
            'capacity' => 30,
        ]);

        $this->actingAs($user)
            ->patch(route('admin.facilities.update', ['resource' => 'buildings', 'id' => $building->id]), [
                'campus_id' => $building->campus_id,
                'name' => $building->name,
                'code' => $building->code,
                'floor_count' => 2,
                'is_active' => true,
            ])
            ->assertSessionHasErrors('floor_count');

        $this->assertSame(3, $building->fresh()->floor_count);
    }

    public function test_building_with_active_room_cannot_be_archived(): void
    {
        $user = $this->userWithPermissions('buildings.delete');
        $building = $this->building();
        Room::create([
            'building_id' => $building->id,
            'name' => 'Ruang 101',
            'code' => 'R-101',
            'floor' => 1,
            'type' => 'Kelas',
            'capacity' => 30,
        ]);

        $this->actingAs($user)
            ->delete(route('admin.facilities.destroy', ['resource' => 'buildings', 'id' => $building->id]))
            ->assertSessionHasErrors('building');

        $this->assertNotSoftDeleted($building);
    }

    public function test_room_can_be_archived_and_restored_with_audit_history(): void
    {
        $user = $this->userWithPermissions('rooms.delete', 'rooms.update');
        $building = $this->building();
        $room = Room::create([
            'building_id' => $building->id,
            'name' => 'Ruang 101',
            'code' => 'R-101',
            'floor' => 1,
            'type' => 'Kelas',
            'capacity' => 30,
        ]);

        $this->actingAs($user)
            ->delete(route('admin.facilities.destroy', ['resource' => 'rooms', 'id' => $room->id]))
            ->assertSessionHasNoErrors();
        $this->assertSoftDeleted($room);

        $this->actingAs($user)
            ->patch(route('admin.facilities.restore', ['resource' => 'rooms', 'id' => $room->id]))
            ->assertSessionHasNoErrors();

        $this->assertNotSoftDeleted($room);
        $this->assertDatabaseHas('audit_logs', ['record_type' => 'rooms', 'record_id' => (string) $room->id, 'action' => 'archived']);
        $this->assertDatabaseHas('audit_logs', ['record_type' => 'rooms', 'record_id' => (string) $room->id, 'action' => 'restored']);
    }

    private function userWithPermissions(string ...$permissions): User
    {
        $user = User::factory()->create();
        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
        $user->givePermissionTo($permissions);

        return $user;
    }

    private function building(int $floors = 2): Building
    {
        $campus = Campus::create(['name' => 'Kampus Utama', 'code' => 'KU']);

        return Building::create([
            'campus_id' => $campus->id,
            'name' => 'Gedung A',
            'code' => 'GA',
            'floor_count' => $floors,
        ]);
    }
}
