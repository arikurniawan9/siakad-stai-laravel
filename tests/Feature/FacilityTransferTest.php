<?php

namespace Tests\Feature;

use App\Models\Building;
use App\Models\Campus;
use App\Models\Room;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class FacilityTransferTest extends TestCase
{
    use RefreshDatabase;

    public function test_facility_transfer_requires_resource_permissions(): void
    {
        $actor = User::factory()->create();
        $file = UploadedFile::fake()->createWithContent('buildings.csv', "campus_code,code,name,floor_count,description,is_active\nKU,GDA,Gedung A,2,,1\n");

        $this->actingAs($actor)->post(route('admin.facilities.import.preview', 'buildings'), ['file' => $file])->assertForbidden();
        $this->get(route('admin.facilities.export', 'buildings'))->assertForbidden();
    }

    public function test_building_import_uses_campus_and_code_as_composite_identity(): void
    {
        $actor = $this->userWithPermissions('buildings.create', 'buildings.update', 'buildings.view', 'rooms.view');
        $first = Campus::create(['name' => 'Kampus Satu', 'code' => 'K1', 'is_active' => true]);
        Campus::create(['name' => 'Kampus Dua', 'code' => 'K2', 'is_active' => true]);
        Building::create(['campus_id' => $first->id, 'name' => 'Gedung Lama', 'code' => 'GDA', 'floor_count' => 2]);

        $location = $this->preview($actor, 'buildings', "campus_code,code,name,floor_count,description,is_active\nK2,GDA,Gedung Baru,3,Gedung kedua,1\n");
        $token = $this->tokenFromLocation($location);
        $this->get($location)->assertInertia(fn (Assert $page) => $page->where('importPreview.error_rows', 0)->where('importPreview.rows.0.action', 'create'));
        $this->post(route('admin.facilities.import.confirm', $token))->assertSessionHasNoErrors();

        $this->assertDatabaseCount('buildings', 2);
        $this->assertDatabaseHas('buildings', ['campus_id' => Campus::where('code', 'K2')->value('id'), 'code' => 'GDA', 'floor_count' => 3]);
        $this->assertDatabaseHas('audit_logs', ['module' => 'facilities', 'action' => 'imported', 'record_type' => 'buildings', 'record_id' => $token]);
    }

    public function test_room_import_resolves_parent_validates_floor_and_normalizes_facilities(): void
    {
        $actor = $this->userWithPermissions('rooms.create', 'rooms.update', 'buildings.view', 'rooms.view');
        $building = $this->building(floors: 2);

        $invalid = $this->preview($actor, 'rooms', "campus_code,building_code,code,name,floor,type,capacity,facilities,is_active\nKU,GDA,LAB-3,Lab Lantai Tiga,3,Laboratorium,30,Proyektor|AC,1\n");
        $this->get($invalid)->assertInertia(fn (Assert $page) => $page->where('importPreview.error_rows', 1)->has('importPreview.rows.0.errors.floor'));

        $valid = $this->preview($actor, 'rooms', "campus_code,building_code,code,name,floor,type,capacity,facilities,is_active\nKU,GDA,LAB-2,Lab Komputer,2,Laboratorium,30,Proyektor|AC|Wi-Fi,1\n");
        $this->post(route('admin.facilities.import.confirm', $this->tokenFromLocation($valid)))->assertSessionHasNoErrors();

        $room = Room::query()->sole();
        $this->assertSame($building->id, $room->building_id);
        $this->assertSame(['Proyektor', 'AC', 'Wi-Fi'], $room->facilities);
    }

    public function test_room_export_contains_parent_codes_and_is_audited(): void
    {
        $actor = $this->userWithPermissions('rooms.view');
        $building = $this->building();
        Room::create(['building_id' => $building->id, 'name' => 'Ruang 101', 'code' => 'R-101', 'floor' => 1, 'type' => 'Kelas', 'capacity' => 40, 'facilities' => ['AC'], 'is_active' => true]);

        $content = $this->actingAs($actor)->get(route('admin.facilities.export', 'rooms'))->assertOk()->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBFcampus_code,building_code,code,name,floor,type,capacity,facilities,is_active", $content);
        $this->assertStringContainsString('KU,GDA,R-101,"Ruang 101",1,Kelas,40,AC,1', $content);
        $this->assertDatabaseHas('audit_logs', ['module' => 'facilities', 'action' => 'exported', 'record_type' => 'rooms']);
    }

    public function test_rooms_can_be_bulk_archived_and_restored_with_audit(): void
    {
        $actor = $this->userWithPermissions('rooms.delete', 'rooms.update');
        $building = $this->building();
        $rooms = collect([1, 2])->map(fn (int $number) => Room::create(['building_id' => $building->id, 'name' => "Ruang {$number}", 'code' => "R-{$number}", 'floor' => 1, 'type' => 'Kelas', 'capacity' => 30]));
        $ids = $rooms->pluck('id')->all();

        $this->actingAs($actor)->post(route('admin.facilities.bulk', 'rooms'), ['action' => 'archive', 'ids' => $ids])->assertSessionHasNoErrors();
        foreach ($rooms as $room) $this->assertSoftDeleted($room);

        $this->post(route('admin.facilities.bulk', 'rooms'), ['action' => 'restore', 'ids' => $ids])->assertSessionHasNoErrors();
        foreach ($rooms as $room) $this->assertNotSoftDeleted($room);
        $this->assertSame(2, \DB::table('audit_logs')->where(['module' => 'facilities', 'record_type' => 'rooms', 'action' => 'archived'])->count());
        $this->assertSame(2, \DB::table('audit_logs')->where(['module' => 'facilities', 'record_type' => 'rooms', 'action' => 'restored'])->count());
    }

    public function test_bulk_archive_is_atomic_when_one_building_has_active_rooms(): void
    {
        $actor = $this->userWithPermissions('buildings.delete');
        $first = $this->building();
        $second = Building::create(['campus_id' => $first->campus_id, 'name' => 'Gedung B', 'code' => 'GDB', 'floor_count' => 2]);
        Room::create(['building_id' => $second->id, 'name' => 'Ruang 1', 'code' => 'R-1', 'floor' => 1, 'type' => 'Kelas', 'capacity' => 30]);

        $this->actingAs($actor)->post(route('admin.facilities.bulk', 'buildings'), ['action' => 'archive', 'ids' => [$first->id, $second->id]])->assertSessionHasErrors('bulk');
        $this->assertNotSoftDeleted($first);
        $this->assertNotSoftDeleted($second);
        $this->assertDatabaseMissing('audit_logs', ['module' => 'facilities', 'action' => 'archived', 'record_type' => 'buildings']);
    }

    public function test_bulk_restore_requires_active_parent(): void
    {
        $actor = $this->userWithPermissions('rooms.update');
        $building = $this->building();
        $room = Room::create(['building_id' => $building->id, 'name' => 'Ruang 1', 'code' => 'R-1', 'floor' => 1, 'type' => 'Kelas', 'capacity' => 30]);
        $room->delete();
        $building->update(['is_active' => false]);

        $this->actingAs($actor)->post(route('admin.facilities.bulk', 'rooms'), ['action' => 'restore', 'ids' => [$room->id]])->assertSessionHasErrors('bulk');
        $this->assertSoftDeleted($room);
    }

    private function preview(User $actor, string $resource, string $csv): string
    {
        $response = $this->actingAs($actor)->post(route('admin.facilities.import.preview', $resource), [
            'file' => UploadedFile::fake()->createWithContent("{$resource}.csv", $csv),
        ])->assertSessionHasNoErrors();

        return (string) $response->headers->get('Location');
    }

    private function tokenFromLocation(string $location): string
    {
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
        $this->assertArrayHasKey('import', $query);

        return (string) $query['import'];
    }

    private function userWithPermissions(string ...$permissions): User
    {
        $user = User::factory()->create();
        foreach ($permissions as $permission) Permission::findOrCreate($permission, 'web');
        $user->givePermissionTo($permissions);

        return $user;
    }

    private function building(int $floors = 2): Building
    {
        $campus = Campus::firstOrCreate(['code' => 'KU'], ['name' => 'Kampus Utama', 'is_active' => true]);

        return Building::create(['campus_id' => $campus->id, 'name' => 'Gedung A', 'code' => 'GDA', 'floor_count' => $floors, 'is_active' => true]);
    }
}
