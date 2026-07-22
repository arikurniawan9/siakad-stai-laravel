<?php

namespace Tests\Feature;

use App\Models\AcademicTerm;
use App\Models\AuditLog;
use App\Models\SystemNotification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

final class OperationsAndNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_notification_center_is_user_scoped_and_read_action_cannot_cross_accounts(): void
    {
        $first = User::factory()->create(); $second = User::factory()->create();
        $mine = SystemNotification::create(['user_id' => $first->id, 'type' => 'finance', 'title' => 'Tagihan baru', 'message' => 'Tagihan UKT tersedia.', 'link' => '/finance']);
        $other = SystemNotification::create(['user_id' => $second->id, 'type' => 'security', 'title' => 'Aktivitas', 'message' => 'Login baru.']);
        $this->actingAs($first)->get(route('notifications.index'))->assertOk()->assertInertia(fn (Assert $page) => $page->component('Notifications/Index')->has('notifications.data', 1)->where('notifications.data.0.id', $mine->id));
        $this->post(route('notifications.read', $other))->assertForbidden();
        $this->post(route('notifications.read', $mine))->assertRedirect('/finance');
        $this->assertNotNull($mine->fresh()->read_at); $this->assertNull($other->fresh()->read_at);
    }

    public function test_audit_center_is_admin_only_and_redacts_sensitive_values(): void
    {
        $admin = $this->permissionUser('Admin', 'settings.view'); $staff = $this->permissionUser('Staff', 'settings.view');
        AuditLog::create(['user_id' => $admin->id, 'module' => 'users', 'action' => 'user_updated', 'record_type' => 'user', 'record_id' => '12', 'new_data' => ['email' => 'user@example.test', 'password' => 'super-secret', 'nested' => ['token' => 'abc']], 'ip_address' => '127.0.0.1']);
        $this->actingAs($staff)->get(route('admin.audit-logs'))->assertForbidden();
        $this->actingAs($admin)->get(route('admin.audit-logs'))->assertOk()->assertInertia(fn (Assert $page) => $page->component('Admin/AuditLogs')->where('logs.data.0.new_data.password', '[REDACTED]')->where('logs.data.0.new_data.nested.token', '[REDACTED]'));
        $this->get(route('admin.audit-logs.export'))->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8');
    }

    public function test_executive_report_requires_permission_and_returns_cross_module_metrics(): void
    {
        $term = AcademicTerm::create(['name' => 'Ganjil 2026', 'code' => '2026-GANJIL', 'semester' => 'Ganjil', 'is_active' => true]);
        $viewer = $this->permissionUser('Pimpinan', 'reports.view');
        $this->actingAs(User::factory()->create())->get(route('reports.index'))->assertForbidden();
        $this->actingAs($viewer)->get(route('reports.index', ['academic_term_id' => $term->id]))->assertOk()->assertInertia(fn (Assert $page) => $page->component('Reports/Index')->where('filters.academic_term_id', (string) $term->id)->has('academic')->has('finance')->has('pmb')->has('quality'));
    }

    private function permissionUser(string $role, string ...$permissions): User
    {
        $user = User::factory()->create(['active_role' => $role]); foreach ($permissions as $permission) Permission::findOrCreate($permission, 'web'); $user->givePermissionTo($permissions); return $user;
    }
}
