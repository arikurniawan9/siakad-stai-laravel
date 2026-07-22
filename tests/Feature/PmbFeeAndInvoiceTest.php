<?php

namespace Tests\Feature;

use App\Domain\Pmb\PmbFeeResolver;
use App\Domain\Pmb\PmbInvoiceService;
use App\Models\AcademicTerm;
use App\Models\PmbApplication;
use App\Models\PmbFee;
use App\Models\Program;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class PmbFeeAndInvoiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_resolver_prefers_the_most_specific_current_fee_without_hardcoded_fallback(): void
    {
        $term = $this->term(active: true);
        $program = $this->program();
        $application = $this->application($program, ['registration_path' => 'Prestasi', 'registration_type' => 'Baru', 'registration_wave' => 'Gelombang 1']);
        $this->fee($term, 100000);
        $this->fee($term, 200000, ['program_id' => $program->id]);
        $this->fee($term, 250000, ['program_id' => $program->id, 'registration_path' => 'Prestasi', 'registration_type' => 'Baru']);
        $specific = $this->fee($term, 300000, ['program_id' => $program->id, 'registration_path' => 'Prestasi', 'registration_type' => 'Baru', 'wave' => 'Gelombang 1']);
        $this->fee($term, 999999, ['program_id' => $program->id, 'registration_path' => 'Prestasi', 'registration_type' => 'Baru', 'wave' => 'Gelombang 1', 'ends_on' => now()->subDay()->toDateString()]);

        $resolved = app(PmbFeeResolver::class)->resolve($application);
        $this->assertSame($specific->id, $resolved->id);
        $this->assertSame('300000.00', $resolved->amount);

        PmbFee::query()->update(['is_active' => false]);
        $this->assertNull(app(PmbFeeResolver::class)->resolveOrNull($application));
    }

    public function test_resolver_uses_only_the_active_academic_term_and_valid_date_window(): void
    {
        $inactiveTerm = $this->term(active: false, code: 'OLD');
        $activeTerm = $this->term(active: true, code: 'ACTIVE');
        $application = $this->application($this->program());
        $this->fee($inactiveTerm, 900000);
        $current = $this->fee($activeTerm, 225000, ['starts_on' => now()->subDay()->toDateString(), 'ends_on' => now()->addDay()->toDateString()]);

        $this->assertSame($current->id, app(PmbFeeResolver::class)->resolve($application)->id);
    }

    public function test_invoice_issue_is_idempotent_and_freezes_the_resolved_amount(): void
    {
        $term = $this->term(active: true);
        $application = $this->application($this->program());
        $fee = $this->fee($term, 275000, ['due_days' => 5]);
        $service = app(PmbInvoiceService::class);

        $first = $service->issue($application);
        $fee->update(['amount' => 350000]);
        $second = $service->issue($application->fresh());

        $this->assertSame($first->id, $second->id);
        $this->assertSame('275000.00', $second->amount);
        $this->assertSame($fee->id, $application->fresh()->pmb_fee_id);
        $this->assertSame(1, \DB::table('pmb_invoices')->where('pmb_application_id', $application->id)->count());
        $this->assertSame(now()->addDays(5)->toDateString(), $second->due_at->toDateString());
    }

    public function test_fee_policy_and_admin_workspace_use_dedicated_permissions(): void
    {
        $outsider = User::factory()->create();
        Permission::findOrCreate('pmb.view', 'web');
        $outsider->givePermissionTo('pmb.view');
        $this->actingAs($outsider)->get(route('admin.pmb'))->assertForbidden();

        $actor = $this->userWithPermissions('pmb_fees.view', 'pmb_fees.create', 'pmb_fees.update', 'pmb_fees.delete');
        $this->assertTrue(Gate::forUser($actor)->allows('viewAny', PmbFee::class));
        $this->actingAs($actor)->get(route('admin.pmb'))->assertOk();
    }

    public function test_admin_can_manage_fees_and_overlapping_scope_is_rejected(): void
    {
        $actor = $this->userWithPermissions('pmb_fees.view', 'pmb_fees.create', 'pmb_fees.update', 'pmb_fees.delete');
        $term = $this->term(active: true);
        $payload = $this->feePayload($term);

        $this->actingAs($actor)->post(route('admin.pmb.fees.store'), $payload)->assertSessionHasNoErrors();
        $fee = PmbFee::query()->sole();
        $this->assertDatabaseHas('audit_logs', ['module' => 'pmb', 'action' => 'fee_created', 'record_type' => 'pmb_fee', 'record_id' => (string) $fee->id]);
        $this->post(route('admin.pmb.fees.store'), $payload)->assertSessionHasErrors('fee');
        $this->assertDatabaseCount('pmb_fees', 1);

        $this->patch(route('admin.pmb.fees.update', $fee), [...$payload, 'amount' => 325000])->assertSessionHasNoErrors();
        $this->assertSame('325000.00', $fee->fresh()->amount);
        $this->delete(route('admin.pmb.fees.destroy', $fee))->assertSessionHasNoErrors();
        $this->assertSoftDeleted($fee);
        $this->patch(route('admin.pmb.fees.restore', $fee->id))->assertSessionHasNoErrors();
        $this->assertNotSoftDeleted($fee);
        $this->assertFalse($fee->fresh()->is_active);
    }

    private function term(bool $active, string $code = '2026-PMB'): AcademicTerm
    {
        return AcademicTerm::create(['name' => "Periode {$code}", 'code' => $code, 'semester' => 'Ganjil', 'is_active' => $active]);
    }

    private function program(): Program
    {
        return Program::create(['name' => 'Teknik Informatika', 'code' => 'TI-'.uniqid(), 'degree' => 'S1', 'is_active' => true]);
    }

    private function application(Program $program, array $overrides = []): PmbApplication
    {
        $user = User::factory()->create();
        return PmbApplication::create([...['user_id' => $user->id, 'program_id' => $program->id, 'registration_number' => 'PMB-2026-'.$user->id, 'full_name' => $user->name, 'email' => $user->email, 'phone' => '081234567890', 'registration_path' => 'Reguler', 'registration_type' => 'Baru', 'status' => 'draft'], ...$overrides]);
    }

    private function fee(AcademicTerm $term, int $amount, array $overrides = []): PmbFee
    {
        return PmbFee::create([...['academic_term_id' => $term->id, 'name' => 'Biaya PMB', 'registration_path' => 'Semua', 'registration_type' => 'Semua', 'amount' => $amount, 'due_days' => 3, 'is_active' => true], ...$overrides]);
    }

    private function feePayload(AcademicTerm $term): array
    {
        return ['academic_term_id' => $term->id, 'program_id' => '', 'name' => 'Biaya Pendaftaran PMB', 'registration_path' => 'Semua', 'registration_type' => 'Semua', 'wave' => '', 'amount' => 250000, 'starts_on' => now()->toDateString(), 'ends_on' => now()->addMonth()->toDateString(), 'due_days' => 3, 'is_active' => true, 'notes' => 'Tarif umum'];
    }

    private function userWithPermissions(string ...$permissions): User
    {
        $user = User::factory()->create();
        foreach ($permissions as $permission) Permission::findOrCreate($permission, 'web');
        $user->givePermissionTo($permissions);
        return $user;
    }
}
