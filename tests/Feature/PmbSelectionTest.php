<?php

namespace Tests\Feature;

use App\Models\AcademicTerm;
use App\Models\PmbApplication;
use App\Models\PmbFee;
use App\Models\PmbInvoice;
use App\Models\PmbSelection;
use App\Models\PmbSelectionComponent;
use App\Models\PmbSelectionResult;
use App\Models\Program;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class PmbSelectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_selection_workspace_and_mutations_require_dedicated_permissions(): void
    {
        $selection = $this->selection();
        $outsider = User::factory()->create();
        $this->actingAs($outsider)->get(route('admin.pmb.selection'))->assertForbidden();

        $viewer = $this->userWithPermissions('pmb_selection.view');
        $this->actingAs($viewer)->get(route('admin.pmb.selection', ['selected' => $selection->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/PmbSelection')
                ->where('selectedSelection.id', $selection->id)
                ->where('abilities.update', false));
        $this->post(route('admin.pmb.selection.components.store', $selection), ['name' => 'Tes', 'weight' => 100, 'max_score' => 100])->assertForbidden();
    }

    public function test_authorized_user_can_create_schedule_and_component_weights_cannot_exceed_one_hundred(): void
    {
        $actor = $this->userWithPermissions('pmb_selection.create', 'pmb_selection.update');
        $term = $this->term();
        $program = $this->program();
        $this->actingAs($actor)->post(route('admin.pmb.selection.store'), [
            'academic_term_id' => $term->id,
            'program_id' => $program->id,
            'name' => 'Seleksi Gelombang Satu',
            'starts_at' => '2026-07-25 08:00:00',
            'ends_at' => '2026-07-25 12:00:00',
            'passing_grade' => 65,
        ])->assertSessionHasNoErrors();

        $selection = PmbSelection::query()->sole();
        $this->post(route('admin.pmb.selection.components.store', $selection), ['name' => 'Tes akademik', 'weight' => 70, 'max_score' => 100, 'sort_order' => 1])->assertSessionHasNoErrors();
        $this->post(route('admin.pmb.selection.components.store', $selection), ['name' => 'Wawancara', 'weight' => 40, 'max_score' => 100, 'sort_order' => 2])->assertSessionHasErrors('weight');
        $this->assertDatabaseCount('pmb_selection_components', 1);
        $this->assertDatabaseHas('audit_logs', ['module' => 'pmb', 'action' => 'selection_created', 'record_id' => (string) $selection->id]);
    }

    public function test_only_verified_candidates_with_paid_invoice_and_matching_term_can_be_assigned(): void
    {
        $actor = $this->userWithPermissions('pmb_selection.update');
        $selection = $this->selection();
        $application = $this->application($selection->academicTerm, $selection->program, invoiceStatus: 'unpaid');
        $this->actingAs($actor)->post(route('admin.pmb.selection.candidates.store', $selection), ['pmb_application_id' => $application->id])->assertSessionHasErrors('pmb_application_id');

        $application->invoice->update(['paid_amount' => 250000, 'status' => 'paid']);
        $this->post(route('admin.pmb.selection.candidates.store', $selection), ['pmb_application_id' => $application->id])->assertSessionHasNoErrors();
        $this->post(route('admin.pmb.selection.candidates.store', $selection), ['pmb_application_id' => $application->id])->assertSessionHasErrors('pmb_application_id');
        $this->assertDatabaseCount('pmb_selection_results', 1);
        $this->assertDatabaseHas('audit_logs', ['module' => 'pmb', 'action' => 'candidate_assigned']);
    }

    public function test_scores_are_bounded_and_finalization_computes_weighted_decisions_atomically(): void
    {
        $actor = $this->userWithPermissions('pmb_selection.update');
        $selection = $this->selection();
        [$academic, $interview] = $this->components($selection);
        $accepted = $this->selectionResult($selection, $this->application($selection->academicTerm, $selection->program));
        $rejected = $this->selectionResult($selection, $this->application($selection->academicTerm, $selection->program));
        $this->actingAs($actor)->put(route('admin.pmb.selection.scores.update', [$selection, $accepted]), ['scores' => [$academic->id => 101, $interview->id => 80]])->assertSessionHasErrors("scores.{$academic->id}");

        $this->put(route('admin.pmb.selection.scores.update', [$selection, $accepted]), ['scores' => [$academic->id => 80, $interview->id => 75]])->assertSessionHasNoErrors();
        $this->put(route('admin.pmb.selection.scores.update', [$selection, $rejected]), ['scores' => [$academic->id => 40, $interview->id => 45]])->assertSessionHasNoErrors();
        $this->post(route('admin.pmb.selection.finalize', $selection))->assertSessionHasNoErrors();

        $this->assertSame('finalized', $selection->fresh()->status);
        $this->assertSame('78.00', $accepted->fresh()->final_score);
        $this->assertSame('accepted', $accepted->fresh()->decision);
        $this->assertSame('42.00', $rejected->fresh()->final_score);
        $this->assertSame('rejected', $rejected->fresh()->decision);
        $this->assertSame('accepted', $accepted->application->fresh()->status);
        $this->assertSame('rejected', $rejected->application->fresh()->status);
        $this->assertDatabaseHas('audit_logs', ['module' => 'pmb', 'action' => 'selection_finalized']);
        $this->put(route('admin.pmb.selection.scores.update', [$selection, $accepted]), ['scores' => [$academic->id => 90, $interview->id => 90]])->assertSessionHasErrors('selection');
    }

    public function test_finalization_rejects_incomplete_weights_or_scores_without_partial_status_changes(): void
    {
        $actor = $this->userWithPermissions('pmb_selection.update');
        $selection = $this->selection();
        $component = PmbSelectionComponent::create(['pmb_selection_id' => $selection->id, 'name' => 'Akademik', 'weight' => 80, 'max_score' => 100]);
        $result = $this->selectionResult($selection, $this->application($selection->academicTerm, $selection->program));
        $this->actingAs($actor)->put(route('admin.pmb.selection.scores.update', [$selection, $result]), ['scores' => [$component->id => 90]])->assertSessionHasNoErrors();
        $this->post(route('admin.pmb.selection.finalize', $selection))->assertSessionHasErrors('selection');
        $this->assertSame('draft', $selection->fresh()->status);
        $this->assertSame('verified', $result->application->fresh()->status);
        $this->assertSame('pending', $result->fresh()->decision);
    }

    public function test_accepted_candidate_conversion_is_idempotent_generates_sequence_and_updates_portal(): void
    {
        $actor = $this->userWithPermissions('pmb_selection.update', 'students.create');
        $selection = $this->selection();
        [$academic, $interview] = $this->components($selection);
        $first = $this->selectionResult($selection, $this->application($selection->academicTerm, $selection->program));
        $second = $this->selectionResult($selection, $this->application($selection->academicTerm, $selection->program));
        $this->actingAs($actor);
        foreach ([$first, $second] as $result) {
            $this->put(route('admin.pmb.selection.scores.update', [$selection, $result]), ['scores' => [$academic->id => 85, $interview->id => 80]])->assertSessionHasNoErrors();
        }
        $this->post(route('admin.pmb.selection.finalize', $selection))->assertSessionHasNoErrors();
        $this->post(route('admin.pmb.selection.convert', [$selection, $first]))->assertSessionHasNoErrors();
        $this->post(route('admin.pmb.selection.convert', [$selection, $first]))->assertSessionHasNoErrors();
        $this->post(route('admin.pmb.selection.convert', [$selection, $second]))->assertSessionHasNoErrors();

        $students = Student::query()->orderBy('nim')->get();
        $this->assertCount(2, $students);
        $this->assertSame('TI20260001', $students[0]->nim);
        $this->assertSame('TI20260002', $students[1]->nim);
        $this->assertSame($first->application->id, $students[0]->pmb_application_id);
        $this->assertSame('Mahasiswa', $first->application->user->fresh()->active_role);
        $this->assertTrue($first->application->user->fresh()->hasRole('Mahasiswa'));
        $this->assertDatabaseHas('student_status_histories', ['student_id' => $students[0]->id, 'from_status' => null, 'to_status' => 'Aktif']);
        $this->assertDatabaseHas('audit_logs', ['module' => 'pmb', 'action' => 'candidate_converted', 'record_id' => (string) $first->application->id]);

        $this->actingAs($first->application->user)->get(route('pmb.application'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('application.selection.decision', 'accepted')
            ->where('application.student.nim', 'TI20260001'));
    }

    public function test_rejected_candidate_cannot_be_converted(): void
    {
        $actor = $this->userWithPermissions('pmb_selection.update', 'students.create');
        $selection = $this->selection();
        [$academic, $interview] = $this->components($selection);
        $result = $this->selectionResult($selection, $this->application($selection->academicTerm, $selection->program));
        $this->actingAs($actor)->put(route('admin.pmb.selection.scores.update', [$selection, $result]), ['scores' => [$academic->id => 20, $interview->id => 20]]);
        $this->post(route('admin.pmb.selection.finalize', $selection))->assertSessionHasNoErrors();
        $this->post(route('admin.pmb.selection.convert', [$selection, $result]))->assertSessionHasErrors('conversion');
        $this->assertDatabaseCount('students', 0);
    }

    private function userWithPermissions(string ...$permissions): User
    {
        $user = User::factory()->create();
        foreach ($permissions as $permission) Permission::findOrCreate($permission, 'web');
        $user->givePermissionTo($permissions);

        return $user;
    }

    private function term(): AcademicTerm
    {
        return AcademicTerm::create(['name' => 'Tahun Akademik 2026/2027', 'code' => 'TERM-'.uniqid(), 'semester' => 'Ganjil', 'starts_on' => '2026-08-01', 'ends_on' => '2027-01-31', 'is_active' => true]);
    }

    private function program(): Program
    {
        return Program::create(['name' => 'Teknik Informatika', 'code' => 'TI', 'degree' => 'S1', 'is_active' => true]);
    }

    private function selection(): PmbSelection
    {
        return PmbSelection::create(['academic_term_id' => $this->term()->id, 'program_id' => $this->program()->id, 'name' => 'Seleksi PMB', 'starts_at' => '2026-07-25 08:00:00', 'ends_at' => '2026-07-25 12:00:00', 'passing_grade' => 60]);
    }

    private function components(PmbSelection $selection): array
    {
        return [
            PmbSelectionComponent::create(['pmb_selection_id' => $selection->id, 'name' => 'Tes akademik', 'weight' => 60, 'max_score' => 100, 'sort_order' => 1]),
            PmbSelectionComponent::create(['pmb_selection_id' => $selection->id, 'name' => 'Wawancara', 'weight' => 40, 'max_score' => 100, 'sort_order' => 2]),
        ];
    }

    private function application(AcademicTerm $term, Program $program, string $invoiceStatus = 'paid'): PmbApplication
    {
        $user = User::factory()->create();
        $fee = PmbFee::create(['academic_term_id' => $term->id, 'program_id' => $program->id, 'name' => 'Biaya PMB', 'registration_path' => 'Semua', 'registration_type' => 'Semua', 'amount' => 250000, 'due_days' => 3, 'is_active' => true]);
        $application = PmbApplication::create([
            'user_id' => $user->id,
            'program_id' => $program->id,
            'pmb_fee_id' => $fee->id,
            'registration_number' => 'PMB-'.strtoupper(uniqid()),
            'full_name' => $user->name,
            'email' => $user->email,
            'phone' => '081234567890',
            'registration_path' => 'Reguler',
            'registration_type' => 'Baru',
            'gender' => 'P',
            'birth_date' => '2007-01-01',
            'address' => 'Jalan Pendidikan',
            'status' => 'verified',
            'submitted_at' => now(),
        ]);
        PmbInvoice::create([
            'pmb_application_id' => $application->id,
            'pmb_fee_id' => $fee->id,
            'invoice_number' => 'INV-'.$application->registration_number,
            'description' => 'Biaya pendaftaran PMB',
            'amount' => 250000,
            'paid_amount' => $invoiceStatus === 'paid' ? 250000 : 0,
            'due_at' => now()->addDays(3),
            'status' => $invoiceStatus,
            'issued_at' => now(),
        ]);

        return $application->fresh(['program', 'fee', 'invoice', 'user']);
    }

    private function selectionResult(PmbSelection $selection, PmbApplication $application): PmbSelectionResult
    {
        return PmbSelectionResult::create(['pmb_selection_id' => $selection->id, 'pmb_application_id' => $application->id]);
    }
}
