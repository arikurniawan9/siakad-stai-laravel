<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class UserTransferTest extends TestCase
{
    use RefreshDatabase;

    private const HEADER = 'email,name,username,roles,active_role,is_active';

    public function test_user_transfer_and_export_require_separate_permissions(): void
    {
        $actor = $this->userWithPermissions('users.view');
        $file = UploadedFile::fake()->createWithContent('users.csv', self::HEADER."\n{$actor->email},Actor,,Staff,Staff,1\n");

        $this->actingAs($actor)->post(route('admin.users.import.preview'), ['file' => $file])->assertForbidden();
        $this->get(route('admin.users.export'))->assertForbidden();
    }

    public function test_existing_account_can_be_safely_synchronized_without_changing_password(): void
    {
        $actor = $this->userWithPermissions('users.view', 'users.update');
        $this->role('Staff');
        $this->role('Dosen');
        $target = User::factory()->create(['email' => 'target@example.test', 'password' => 'kata-sandi-tetap']);
        $target->assignRole('Staff');
        $password = $target->password;
        $csv = self::HEADER."\ntarget@example.test,Nama Sinkron,target-sync,Staff|Dosen,Dosen,1\n";

        $location = $this->preview($actor, $csv);
        $token = $this->tokenFromLocation($location);
        $this->get($location)->assertInertia(fn (Assert $page) => $page
            ->where('importPreview.error_rows', 0)
            ->where('importPreview.rows.0.action', 'update'));
        $this->post(route('admin.users.import.confirm', $token))->assertSessionHasNoErrors();

        $target->refresh();
        $this->assertSame('Nama Sinkron', $target->name);
        $this->assertSame('target-sync', $target->username);
        $this->assertSame($password, $target->password);
        $this->assertTrue($target->hasAllRoles(['Staff', 'Dosen']));
        $this->assertSame('Dosen', $target->active_role);
        $log = (string) \DB::table('audit_logs')->where(['module' => 'users', 'action' => 'imported', 'record_id' => $token])->value('new_data');
        $this->assertStringNotContainsString('password', strtolower($log));
        $this->assertStringNotContainsString($password, $log);
    }

    public function test_preview_rejects_unknown_archived_and_duplicate_accounts(): void
    {
        $actor = $this->userWithPermissions('users.view', 'users.update');
        $this->role('Staff');
        $archived = User::factory()->create(['email' => 'archived@example.test']);
        $archived->delete();
        $csv = self::HEADER."\nunknown@example.test,Unknown,,Staff,Staff,1\narchived@example.test,Archived,,Staff,Staff,1\nunknown@example.test,Duplicate,,Staff,Staff,1\n";

        $location = $this->preview($actor, $csv);
        $this->get($location)->assertInertia(fn (Assert $page) => $page
            ->where('importPreview.error_rows', 3)
            ->has('importPreview.rows.0.errors.email')
            ->has('importPreview.rows.1.errors.email')
            ->has('importPreview.rows.2.errors.email'));
    }

    public function test_import_preserves_admin_continuity_across_the_whole_file(): void
    {
        $actor = $this->userWithPermissions('users.view', 'users.update');
        $this->role('Admin');
        $this->role('Staff');
        $oldAdmin = User::factory()->create(['email' => 'old-admin@example.test', 'is_active' => true, 'active_role' => 'Admin']);
        $oldAdmin->assignRole('Admin');
        $nextAdmin = User::factory()->create(['email' => 'next-admin@example.test', 'is_active' => true, 'active_role' => 'Staff']);
        $nextAdmin->assignRole('Staff');
        $csv = self::HEADER."\nold-admin@example.test,Old Admin,,Staff,Staff,0\nnext-admin@example.test,Next Admin,,Admin,Admin,1\n";

        $location = $this->preview($actor, $csv);
        $this->get($location)->assertInertia(fn (Assert $page) => $page->where('importPreview.error_rows', 0));
        $this->post(route('admin.users.import.confirm', $this->tokenFromLocation($location)))->assertSessionHasNoErrors();

        $this->assertFalse($oldAdmin->fresh()->is_active);
        $this->assertFalse($oldAdmin->fresh()->hasRole('Admin'));
        $this->assertTrue($nextAdmin->fresh()->is_active);
        $this->assertTrue($nextAdmin->fresh()->hasRole('Admin'));
    }

    public function test_preview_rejects_removing_the_last_active_admin(): void
    {
        $actor = $this->userWithPermissions('users.view', 'users.update');
        $this->role('Admin');
        $this->role('Staff');
        $admin = User::factory()->create(['email' => 'last-admin@example.test', 'is_active' => true, 'active_role' => 'Admin']);
        $admin->assignRole('Admin');

        $location = $this->preview($actor, self::HEADER."\nlast-admin@example.test,Last Admin,,Staff,Staff,0\n");
        $this->get($location)->assertInertia(fn (Assert $page) => $page
            ->where('importPreview.error_rows', 1)
            ->has('importPreview.rows.0.errors.roles'));
    }

    public function test_user_export_omits_credentials_and_is_audited(): void
    {
        $actor = $this->userWithPermissions('users.export');
        $this->role('Staff');
        $target = User::factory()->create(['email' => 'export@example.test', 'password' => 'secret-that-must-not-leak', 'active_role' => 'Staff']);
        $target->assignRole('Staff');

        $content = $this->actingAs($actor)->get(route('admin.users.export'))->assertOk()->streamedContent();

        $this->assertStringStartsWith("\xEF\xBB\xBF".self::HEADER, $content);
        $this->assertStringContainsString('export@example.test', $content);
        $this->assertStringNotContainsString($target->password, $content);
        $this->assertStringNotContainsString('password', strtolower($content));
        $this->assertDatabaseHas('audit_logs', ['module' => 'users', 'action' => 'exported', 'record_type' => 'user']);
    }

    public function test_accounts_can_be_bulk_deactivated_activated_archived_and_restored(): void
    {
        $actor = $this->userWithPermissions('users.update', 'users.delete');
        $this->role('Staff');
        $targets = User::factory()->count(2)->create(['is_active' => true, 'active_role' => 'Staff']);
        $targets->each->assignRole('Staff');
        $ids = $targets->pluck('id')->all();

        $this->actingAs($actor)->post(route('admin.users.bulk'), ['action' => 'deactivate', 'ids' => $ids])->assertSessionHasNoErrors();
        $this->assertSame(0, User::query()->whereIn('id', $ids)->where('is_active', true)->count());
        $this->post(route('admin.users.bulk'), ['action' => 'activate', 'ids' => $ids])->assertSessionHasNoErrors();
        $this->assertSame(2, User::query()->whereIn('id', $ids)->where('is_active', true)->count());
        $this->post(route('admin.users.bulk'), ['action' => 'archive', 'ids' => $ids])->assertSessionHasNoErrors();
        foreach ($targets as $target) $this->assertSoftDeleted($target);
        $this->post(route('admin.users.bulk'), ['action' => 'restore', 'ids' => $ids])->assertSessionHasNoErrors();
        foreach ($targets as $target) {
            $this->assertNotSoftDeleted($target);
            $this->assertFalse($target->fresh()->is_active);
        }
    }

    public function test_bulk_action_is_atomic_for_current_account_and_last_admin(): void
    {
        $actor = $this->userWithPermissions('users.update', 'users.delete');
        $this->role('Admin');
        $this->role('Staff');
        $admin = User::factory()->create(['is_active' => true, 'active_role' => 'Admin']);
        $admin->assignRole('Admin');
        $staff = User::factory()->create(['is_active' => true, 'active_role' => 'Staff']);
        $staff->assignRole('Staff');

        $this->actingAs($actor)->post(route('admin.users.bulk'), ['action' => 'deactivate', 'ids' => [$admin->id, $staff->id]])->assertSessionHasErrors('bulk');
        $this->assertTrue($admin->fresh()->is_active);
        $this->assertTrue($staff->fresh()->is_active);

        $this->post(route('admin.users.bulk'), ['action' => 'archive', 'ids' => [$actor->id, $staff->id]])->assertSessionHasErrors('bulk');
        $this->assertNotSoftDeleted($actor);
        $this->assertNotSoftDeleted($staff);
    }

    private function preview(User $actor, string $csv): string
    {
        $response = $this->actingAs($actor)->post(route('admin.users.import.preview'), [
            'file' => UploadedFile::fake()->createWithContent('users.csv', $csv),
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

    private function role(string $name): Role
    {
        return Role::findOrCreate($name, 'web');
    }
}
