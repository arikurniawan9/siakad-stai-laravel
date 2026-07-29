<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SuperAdminPortalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    public function test_first_visit_opens_super_admin_setup(): void
    {
        $this->get('/superadmin')
            ->assertRedirect('/superadmin/setup');

        $this->get('/superadmin/setup')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('SuperAdmin/Setup')
                ->where('defaults.username', 'superadmin')
                ->where('database.exists', true)
            );
    }

    public function test_setup_creates_and_authenticates_the_first_super_admin(): void
    {
        $response = $this->post('/superadmin/setup', [
            'name' => 'System Owner',
            'username' => 'superadmin',
            'email' => 'owner@example.test',
            'password' => 'Strong!Password123',
            'password_confirmation' => 'Strong!Password123',
        ]);

        $response->assertRedirect('/superadmin/portal');
        $user = User::query()->where('email', 'owner@example.test')->firstOrFail();

        $this->assertAuthenticatedAs($user);
        $this->assertTrue($user->hasRole('Super Admin'));
        $this->assertSame('Super Admin', $user->active_role);
    }

    public function test_setup_is_closed_after_super_admin_exists(): void
    {
        $superAdmin = $this->superAdmin();

        $this->get('/superadmin/setup')->assertRedirect('/superadmin/login');
        $this->actingAs($superAdmin)->get('/superadmin/setup')->assertRedirect('/superadmin/portal');
        $this->post('/superadmin/setup', [
            'name' => 'Another Owner',
            'username' => 'another',
            'email' => 'another@example.test',
            'password' => 'Strong!Password123',
            'password_confirmation' => 'Strong!Password123',
        ])->assertConflict();
    }

    public function test_regular_administrator_cannot_open_super_admin_portal(): void
    {
        $this->superAdmin();
        $admin = User::factory()->create(['is_active' => true]);

        $this->actingAs($admin)->get('/superadmin/portal')->assertForbidden();
    }

    public function test_super_admin_can_login_through_dedicated_login(): void
    {
        $superAdmin = $this->superAdmin();

        $this->post('/superadmin/login', [
            'identifier' => $superAdmin->email,
            'password' => 'Strong!Password123',
        ])->assertRedirect('/superadmin/portal');

        $this->assertAuthenticatedAs($superAdmin);
    }

    public function test_bsi_secret_is_encrypted_and_never_returned_to_the_page(): void
    {
        $superAdmin = $this->superAdmin();

        $this->actingAs($superAdmin)->put('/superadmin/settings/bsi', [
            'enabled' => true,
            'environment' => 'sandbox',
            'base_url' => 'https://sandbox.example.test',
            'callback_secret' => 'callback-secret-that-is-long',
            'timeout' => 15,
            'signature_tolerance_seconds' => 300,
            'strategy' => 'student',
        ])->assertRedirect();

        $stored = (string) Storage::disk('local')->get('system/bsi.json');
        $this->assertStringNotContainsString('callback-secret-that-is-long', $stored);

        $this->actingAs($superAdmin)->get('/superadmin/portal')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('SuperAdmin/Index')
                ->where('bsi.callback_secret_configured', true)
                ->missing('bsi.callback_secret')
            );
    }

    public function test_production_bsi_cannot_be_enabled_without_real_adapter(): void
    {
        $this->actingAs($this->superAdmin())->put('/superadmin/settings/bsi', [
            'enabled' => true,
            'environment' => 'production',
            'base_url' => 'https://production.example.test',
            'callback_secret' => 'callback-secret-that-is-long',
            'timeout' => 15,
            'signature_tolerance_seconds' => 300,
            'strategy' => 'student',
        ])->assertSessionHasErrors('enabled');
    }

    public function test_super_admin_can_download_a_private_database_backup(): void
    {
        $filename = 'siakad_20260730_001043.sql';
        Storage::disk('local')->put('backups/database/'.$filename, 'SELECT 1;');

        $this->actingAs($this->superAdmin())
            ->get('/superadmin/database/backups/'.$filename)
            ->assertOk()
            ->assertDownload($filename)
            ->assertHeader('Content-Type', 'application/octet-stream');
    }

    public function test_restore_and_delete_require_exact_database_confirmation(): void
    {
        $superAdmin = $this->superAdmin();

        $this->actingAs($superAdmin)->post('/superadmin/database/restore', [
            'backup' => UploadedFile::fake()->createWithContent('backup.sql', 'SELECT 1;'),
            'password' => 'Strong!Password123',
            'confirmation' => 'RESTORE wrong',
        ])->assertSessionHasErrors('confirmation');

        $this->actingAs($superAdmin)->delete('/superadmin/database', [
            'password' => 'Strong!Password123',
            'confirmation' => 'HAPUS wrong',
        ])->assertSessionHasErrors('confirmation');
    }

    private function superAdmin(): User
    {
        $role = Role::findOrCreate('Super Admin', 'web');
        $user = User::factory()->create([
            'name' => 'System Owner',
            'username' => 'superadmin',
            'email' => 'owner@example.test',
            'password' => 'Strong!Password123',
            'is_active' => true,
            'active_role' => 'Super Admin',
        ]);
        $user->syncRoles([$role]);

        return $user;
    }
}
