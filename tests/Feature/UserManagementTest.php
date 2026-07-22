<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_without_permission_cannot_open_user_management(): void
    {
        $this->actingAs(User::factory()->create())->get(route('admin.users'))->assertForbidden();
    }

    public function test_authorized_user_can_open_user_management(): void
    {
        $actor = $this->userWithPermissions('users.view');
        $target = User::factory()->create(['name' => 'Budi Pengguna']);
        $target->assignRole($this->role('Staff'));

        $this->actingAs($actor)->get(route('admin.users', ['selected' => $target->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Admin/Users')->where('selectedUser.id', $target->id)->has('users.data', 2));
    }

    public function test_authorized_user_can_create_multi_role_account_with_audit(): void
    {
        $actor = $this->userWithPermissions('users.create');
        $this->role('Dosen');
        $this->role('Prodi');

        $this->actingAs($actor)->post(route('admin.users.store'), $this->payload([
            'roles' => ['Dosen', 'Prodi'],
            'active_role' => 'Dosen',
        ]))->assertSessionHasNoErrors();

        $user = User::query()->where('email', 'new.user@example.test')->sole();
        $this->assertTrue(Hash::check('rahasia-ku-aman', $user->password));
        $this->assertTrue($user->hasAllRoles(['Dosen', 'Prodi']));
        $this->assertSame('Dosen', $user->active_role);
        $log = (string) \DB::table('audit_logs')->where(['module' => 'users', 'action' => 'created', 'record_id' => (string) $user->id])->value('new_data');
        $this->assertStringNotContainsString('rahasia-ku-aman', $log);
        $this->assertStringNotContainsString('password', $log);
    }

    public function test_active_role_must_be_owned_and_unique_identity_is_enforced(): void
    {
        $actor = $this->userWithPermissions('users.create');
        $this->role('Staff');
        User::factory()->create(['email' => 'new.user@example.test', 'username' => 'new-user']);

        $this->actingAs($actor)->post(route('admin.users.store'), $this->payload([
            'active_role' => 'Admin',
        ]))->assertSessionHasErrors(['email', 'username', 'active_role']);
    }

    public function test_account_can_be_updated_without_changing_password(): void
    {
        $actor = $this->userWithPermissions('users.update');
        $this->role('Staff');
        $this->role('Keuangan');
        $target = User::factory()->create(['password' => 'kata-sandi-awal']);
        $target->assignRole('Staff');
        $password = $target->password;

        $this->actingAs($actor)->patch(route('admin.users.update', $target), $this->payload([
            'name' => 'Nama Diperbarui',
            'email' => $target->email,
            'username' => '',
            'password' => '',
            'password_confirmation' => '',
            'roles' => ['Staff', 'Keuangan'],
            'active_role' => 'Keuangan',
        ]))->assertSessionHasNoErrors();

        $target->refresh();
        $this->assertSame('Nama Diperbarui', $target->name);
        $this->assertNull($target->username);
        $this->assertSame($password, $target->password);
        $this->assertTrue($target->hasAllRoles(['Staff', 'Keuangan']));
        $this->assertSame('Keuangan', $target->active_role);
    }

    public function test_last_active_admin_cannot_be_deactivated_or_lose_admin_role(): void
    {
        $actor = $this->userWithPermissions('users.update', 'users.delete');
        $this->role('Admin');
        $this->role('Staff');
        $target = User::factory()->create(['active_role' => 'Admin']);
        $target->assignRole('Admin');

        $response = $this->actingAs($actor)->patch(route('admin.users.update', $target), $this->payload([
            'email' => $target->email,
            'username' => '',
            'is_active' => false,
            'roles' => ['Staff'],
            'active_role' => 'Staff',
            'password' => '',
            'password_confirmation' => '',
        ]));

        $response->assertSessionHasErrors('roles');
        $this->assertTrue($target->fresh()->is_active);
        $this->assertTrue($target->fresh()->hasRole('Admin'));

        $this->actingAs($actor)->delete(route('admin.users.destroy', $target))->assertSessionHasErrors('roles');
        $this->assertNotSoftDeleted($target);
    }

    public function test_admin_can_be_deactivated_when_another_active_admin_exists(): void
    {
        $actor = $this->userWithPermissions('users.update');
        $this->role('Admin');
        $actor->assignRole('Admin');
        $target = User::factory()->create(['active_role' => 'Admin']);
        $target->assignRole('Admin');

        $this->actingAs($actor)->patch(route('admin.users.update', $target), $this->payload([
            'email' => $target->email,
            'username' => '',
            'is_active' => false,
            'roles' => ['Admin'],
            'active_role' => 'Admin',
            'password' => '',
            'password_confirmation' => '',
        ]))->assertSessionHasNoErrors();

        $this->assertFalse($target->fresh()->is_active);
    }

    public function test_current_account_cannot_be_deactivated_or_archived(): void
    {
        $actor = $this->userWithPermissions('users.update', 'users.delete');
        $this->role('Staff');
        $actor->assignRole('Staff');

        $this->actingAs($actor)->patch(route('admin.users.update', $actor), $this->payload([
            'email' => $actor->email,
            'username' => '',
            'is_active' => false,
            'roles' => ['Staff'],
            'active_role' => 'Staff',
            'password' => '',
            'password_confirmation' => '',
        ]))->assertSessionHasErrors('is_active');

        $this->actingAs($actor)->delete(route('admin.users.destroy', $actor))->assertSessionHasErrors('user');
        $this->assertNotSoftDeleted($actor);
    }

    public function test_account_can_be_archived_and_restored_but_remains_inactive(): void
    {
        $actor = $this->userWithPermissions('users.delete', 'users.update');
        $this->role('Staff');
        $target = User::factory()->create(['active_role' => 'Staff']);
        $target->assignRole('Staff');

        $this->actingAs($actor)->delete(route('admin.users.destroy', $target))->assertRedirect();
        $this->assertSoftDeleted($target);
        $this->assertFalse(User::withTrashed()->findOrFail($target->id)->is_active);

        $this->actingAs($actor)->patch(route('admin.users.restore', $target->id))->assertRedirect();
        $this->assertNotSoftDeleted($target);
        $this->assertFalse($target->fresh()->is_active);
        $this->assertDatabaseHas('audit_logs', ['module' => 'users', 'action' => 'archived', 'record_id' => (string) $target->id]);
        $this->assertDatabaseHas('audit_logs', ['module' => 'users', 'action' => 'restored', 'record_id' => (string) $target->id]);
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

    private function payload(array $overrides = []): array
    {
        $this->role('Staff');

        return [...[
            'name' => 'Pengguna Baru',
            'username' => 'new-user',
            'email' => 'new.user@example.test',
            'password' => 'rahasia-ku-aman',
            'password_confirmation' => 'rahasia-ku-aman',
            'is_active' => true,
            'roles' => ['Staff'],
            'active_role' => 'Staff',
        ], ...$overrides];
    }
}
