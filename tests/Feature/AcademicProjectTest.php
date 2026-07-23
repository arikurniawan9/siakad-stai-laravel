<?php

namespace Tests\Feature;

use App\Domain\Projects\AcademicProjectService;
use App\Domain\Projects\AcademicProjectProgressService;
use App\Models\AcademicProject;
use App\Models\AcademicTerm;
use App\Models\Lecturer;
use App\Models\Program;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class AcademicProjectTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('siakad.projects.minimum_gpa', 0);
        config()->set('siakad.projects.minimum_credits.thesis', 0);
        config()->set('siakad.projects.minimum_credits.internship', 0);
        config()->set('siakad.projects.minimum_credits.community_service', 0);
        Storage::fake('local');
    }

    public function test_user_without_permission_cannot_open_project_workspace(): void
    {
        $this->actingAs(User::factory()->create(['active_role' => 'Mahasiswa']))->get(route('academic.projects'))->assertForbidden();
    }

    public function test_student_can_create_draft_but_cannot_create_duplicate_open_project(): void
    {
        $context = $this->studentContext();
        $payload = $this->payload($context);

        $response = $this->actingAs($context['user'])->post(route('academic.projects.store'), $payload);
        $response->assertRedirect();
        $this->assertDatabaseHas('academic_projects', ['student_id' => $context['student']->id, 'project_type' => 'thesis', 'status' => 'draft']);

        $this->actingAs($context['user'])->post(route('academic.projects.store'), $payload)->assertSessionHasErrors('project_type');
        $this->assertDatabaseCount('academic_projects', 1);
    }

    public function test_submit_requires_private_proposal_and_stores_eligibility_snapshot(): void
    {
        $context = $this->studentContext();
        $project = app(AcademicProjectService::class)->create($context['student'], $this->payload($context), $context['user']);

        $this->actingAs($context['user'])->post(route('academic.projects.submit', $project))->assertSessionHasErrors('document');
        $this->actingAs($context['user'])->post(route('academic.projects.documents.store', $project), ['document_type' => 'proposal', 'document' => UploadedFile::fake()->create('proposal.pdf', 100, 'application/pdf')])->assertSessionHasNoErrors();
        $this->actingAs($context['user'])->post(route('academic.projects.submit', $project))->assertSessionHasNoErrors();

        $project->refresh();
        $this->assertSame('submitted', $project->status);
        $this->assertTrue($project->eligibility_snapshot['eligible']);
        $this->assertDatabaseHas('audit_logs', ['module' => 'academic_projects', 'action' => 'project_submitted', 'record_id' => (string) $project->id]);
        Storage::disk('local')->assertExists($project->documents()->first()->path);
    }

    public function test_revision_upload_keeps_version_history_and_document_is_owner_scoped(): void
    {
        $context = $this->submittedProject();
        $manager = $this->user('Prodi', 'projects.view', 'projects.update');
        $this->actingAs($manager)->post(route('academic.projects.decision', $context['project']), ['decision' => 'revision', 'notes' => 'Perbaiki metodologi dan jadwal penelitian.'])->assertSessionHasNoErrors();
        $this->actingAs($context['user'])->post(route('academic.projects.documents.store', $context['project']), ['document_type' => 'proposal', 'document' => UploadedFile::fake()->create('proposal-v2.pdf', 120, 'application/pdf')])->assertSessionHasNoErrors();

        $documents = $context['project']->documents()->where('document_type', 'proposal')->orderBy('version')->get();
        $this->assertCount(2, $documents);
        $this->assertFalse($documents[0]->is_current);
        $this->assertTrue($documents[1]->is_current);
        $this->actingAs($context['user'])->get(route('academic.projects.documents.show', [$context['project'], $documents[1]]))->assertOk();

        $outsider = $this->studentContext('TI02', 'MHS002');
        $this->actingAs($outsider['user'])->get(route('academic.projects.documents.show', [$context['project'], $documents[1]]))->assertForbidden();
    }

    public function test_approved_project_can_assign_supervisor_and_only_assigned_lecturer_can_view(): void
    {
        $context = $this->submittedProject();
        $manager = $this->user('Prodi', 'projects.view', 'projects.update');
        app(AcademicProjectService::class)->decide($context['project'], 'approve', 'Proposal memenuhi syarat.', $manager);
        $lecturerUser = $this->user('Dosen', 'projects.view', 'projects.update');
        $lecturer = Lecturer::create(['user_id' => $lecturerUser->id, 'program_id' => $context['program']->id, 'name' => 'Dosen Pembimbing', 'nidn' => '12345678', 'employment_status' => 'Tetap', 'is_active' => true]);

        $this->actingAs($manager)->put(route('academic.projects.assignments.update', $context['project']), ['supervisor_ids' => [$lecturer->id], 'examiner_ids' => []])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('academic_project_lecturers', ['academic_project_id' => $context['project']->id, 'lecturer_id' => $lecturer->id, 'role' => 'supervisor', 'sequence' => 1]);
        $this->assertDatabaseHas('system_notifications', ['user_id' => $lecturerUser->id, 'type' => 'academic_project_assignment']);
        $this->actingAs($lecturerUser)->get(route('academic.projects', ['selected' => $context['project']->id]))->assertOk();

        $outsiderUser = $this->user('Dosen', 'projects.view', 'projects.update');
        Lecturer::create(['user_id' => $outsiderUser->id, 'program_id' => $context['program']->id, 'name' => 'Dosen Luar', 'nidn' => '87654321', 'employment_status' => 'Tetap', 'is_active' => true]);
        $this->assertFalse($outsiderUser->can('view', $context['project']));
    }

    public function test_assignment_rejects_same_lecturer_as_supervisor_and_examiner(): void
    {
        $context = $this->submittedProject();
        $manager = $this->user('Prodi', 'projects.view', 'projects.update');
        app(AcademicProjectService::class)->decide($context['project'], 'approve', null, $manager);
        $lecturer = Lecturer::create(['program_id' => $context['program']->id, 'name' => 'Dosen Rangkap', 'nidn' => '11112222', 'employment_status' => 'Tetap', 'is_active' => true]);

        $this->actingAs($manager)->put(route('academic.projects.assignments.update', $context['project']), ['supervisor_ids' => [$lecturer->id], 'examiner_ids' => [$lecturer->id]])->assertSessionHasErrors('examiner_ids');
        $this->assertDatabaseCount('academic_project_lecturers', 0);
    }

    public function test_student_logbook_is_reviewed_only_by_assigned_supervisor_and_guidance_is_notified(): void
    {
        $context = $this->activeProject();
        $payload = ['activity_on' => now()->subDay()->toDateString(), 'hours' => 3, 'activity' => 'Menyusun instrumen penelitian awal.', 'progress' => 'Instrumen penelitian selesai lima puluh persen.', 'obstacles' => 'Referensi terbatas.', 'next_plan' => 'Validasi bersama pembimbing.'];
        $this->actingAs($context['user'])->post(route('academic.projects.logbooks.store', $context['project']), $payload)->assertSessionHasNoErrors();
        $logbook = $context['project']->logbooks()->firstOrFail();

        $outsider = $this->user('Dosen', 'projects.view', 'projects.update');
        $this->actingAs($outsider)->post(route('academic.projects.logbooks.review', [$context['project'], $logbook]), ['decision' => 'verify', 'notes' => 'Baik.'])->assertForbidden();
        $this->actingAs($context['supervisor_user'])->post(route('academic.projects.logbooks.review', [$context['project'], $logbook]), ['decision' => 'verify', 'notes' => 'Progres telah sesuai target.'])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('academic_project_logbooks', ['id' => $logbook->id, 'status' => 'verified', 'reviewed_by' => $context['supervisor_user']->id]);

        $this->actingAs($context['supervisor_user'])->post(route('academic.projects.guidance.store', $context['project']), ['occurred_at' => now()->subHour()->format('Y-m-d H:i:s'), 'mode' => 'onsite', 'discussion' => 'Pembahasan validitas instrumen penelitian.', 'feedback' => 'Perbaiki indikator pada variabel kedua.', 'follow_up' => 'Kirim revisi sebelum pertemuan berikutnya.'])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('academic_project_guidance_records', ['academic_project_id' => $context['project']->id, 'lecturer_id' => $context['supervisor']->id]);
        $this->assertDatabaseHas('system_notifications', ['user_id' => $context['user']->id, 'type' => 'academic_project_guidance']);
    }

    public function test_defense_rubric_requires_each_examiner_score_then_produces_locked_minutes_pdf(): void
    {
        $context = $this->activeProject();
        $progress = app(AcademicProjectProgressService::class);
        $defense = $progress->scheduleDefense($context['project'], ['defense_type' => 'defense', 'scheduled_at' => '2026-12-10 08:00', 'ends_at' => '2026-12-10 10:00', 'room_id' => null, 'delivery_mode' => 'online'], $context['manager']);
        $items = $progress->saveRubric($context['project'], $defense, [['name' => 'Substansi', 'weight' => 60, 'max_score' => 100], ['name' => 'Presentasi', 'weight' => 40, 'max_score' => 100]]);
        $completion = ['result' => 'passed', 'minutes_summary' => 'Sidang berlangsung tertib dan seluruh pertanyaan dijawab dengan baik.', 'incidents' => null];

        try {
            $progress->completeDefense($context['project'], $defense, $completion, $context['manager']);
            $this->fail('Finalisasi tanpa nilai seharusnya ditolak.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('scores', $exception->errors());
        }

        $progress->saveScores($context['project'], $defense, [['rubric_item_id' => $items[0]->id, 'score' => 80, 'notes' => null], ['rubric_item_id' => $items[1]->id, 'score' => 90, 'notes' => null]], $context['examiner_user']);
        $completed = $progress->completeDefense($context['project'], $defense, $completion, $context['manager']);
        $this->assertSame('completed', $completed->status);
        $this->assertSame('84.00', $completed->final_score);
        $this->actingAs($context['manager'])->get(route('academic.projects.defenses.minutes', [$context['project'], $defense]))->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->get(route('academic.projects.defenses.verify', $defense->verification_code))->assertOk()->assertSee('DOKUMEN VALID');

        $this->expectException(ValidationException::class);
        $progress->completeDefense($context['project'], $defense, $completion, $context['manager']);
    }

    public function test_defense_rejects_overlapping_lecturer_assignment(): void
    {
        $first = $this->activeProject();
        $secondBase = $this->submittedProject('TI03', 'MHS003');
        $manager = $first['manager'];
        $service = app(AcademicProjectService::class);
        $service->decide($secondBase['project'], 'approve', null, $manager);
        $service->syncAssignments($secondBase['project'], ['supervisor_ids' => [$first['supervisor']->id], 'examiner_ids' => [$first['examiner']->id]], $manager);
        $progress = app(AcademicProjectProgressService::class);
        $payload = ['defense_type' => 'defense', 'scheduled_at' => '2026-12-11 08:00', 'ends_at' => '2026-12-11 10:00', 'room_id' => null, 'delivery_mode' => 'online'];
        $progress->scheduleDefense($first['project'], $payload, $manager);

        $this->expectException(ValidationException::class);
        $progress->scheduleDefense($secondBase['project']->fresh(), $payload, $manager);
    }

    public function test_passed_defense_and_final_file_publish_idempotent_repository(): void
    {
        $context = $this->activeProject();
        $progress = app(AcademicProjectProgressService::class);
        $defense = $progress->scheduleDefense($context['project'], ['defense_type' => 'final_seminar', 'scheduled_at' => '2026-12-12 08:00', 'ends_at' => '2026-12-12 10:00', 'room_id' => null, 'delivery_mode' => 'online'], $context['manager']);
        $items = $progress->saveRubric($context['project'], $defense, [['name' => 'Kualitas laporan', 'weight' => 100, 'max_score' => 100]]);
        $progress->saveScores($context['project'], $defense, [['rubric_item_id' => $items[0]->id, 'score' => 88, 'notes' => null]], $context['examiner_user']);
        $progress->completeDefense($context['project'], $defense, ['result' => 'passed', 'minutes_summary' => 'Seminar akhir dinyatakan lulus dan laporan dapat diterbitkan.', 'incidents' => null], $context['manager']);
        app(AcademicProjectService::class)->storeDocument($context['project'], 'final_report', UploadedFile::fake()->create('laporan-final.pdf', 200, 'application/pdf'), $context['user']);
        $payload = ['title' => 'Analisis Sistem Informasi Akademik Terpadu', 'abstract' => str_repeat('Abstrak final kegiatan akademik yang telah diselesaikan. ', 2), 'keywords' => ['siakad', 'integrasi', 'akademik'], 'publication_consent' => true];

        $first = $progress->publishRepository($context['project'], $payload, $context['manager']);
        $second = $progress->publishRepository($context['project']->fresh(), $payload, $context['manager']);
        $this->assertSame($first->id, $second->id);
        $this->assertSame('completed', $context['project']->fresh()->status);
        $this->get(route('academic.projects.repository.public', $first->verification_code))->assertOk()->assertSee('REPOSITORY AKADEMIK TERVERIFIKASI');
        $this->get(route('academic.projects.repository.download', $first->verification_code))->assertOk();
    }

    private function submittedProject(string $programCode = 'TI01', string $nim = 'MHS001'): array
    {
        $context = $this->studentContext($programCode, $nim);
        $project = app(AcademicProjectService::class)->create($context['student'], $this->payload($context), $context['user']);
        app(AcademicProjectService::class)->storeDocument($project, 'proposal', UploadedFile::fake()->create('proposal.pdf', 100, 'application/pdf'), $context['user']);
        app(AcademicProjectService::class)->submit($project, $context['user']);

        return [...$context, 'project' => $project->fresh()];
    }

    private function activeProject(): array
    {
        $context = $this->submittedProject();
        $manager = $this->user('Prodi', 'projects.view', 'projects.update');
        $supervisorUser = $this->user('Dosen', 'projects.view', 'projects.update');
        $examinerUser = $this->user('Dosen', 'projects.view', 'projects.update');
        $supervisor = Lecturer::create(['user_id' => $supervisorUser->id, 'program_id' => $context['program']->id, 'name' => 'Pembimbing Utama', 'nidn' => '10000001', 'employment_status' => 'Tetap', 'is_active' => true]);
        $examiner = Lecturer::create(['user_id' => $examinerUser->id, 'program_id' => $context['program']->id, 'name' => 'Penguji Utama', 'nidn' => '10000002', 'employment_status' => 'Tetap', 'is_active' => true]);
        $service = app(AcademicProjectService::class);
        $service->decide($context['project'], 'approve', 'Proposal layak dilanjutkan.', $manager);
        $project = $service->syncAssignments($context['project'], ['supervisor_ids' => [$supervisor->id], 'examiner_ids' => [$examiner->id]], $manager);

        return [...$context, 'project' => $project, 'manager' => $manager, 'supervisor' => $supervisor, 'examiner' => $examiner, 'supervisor_user' => $supervisorUser, 'examiner_user' => $examinerUser];
    }

    private function studentContext(string $programCode = 'TI01', string $nim = 'MHS001'): array
    {
        $user = $this->user('Mahasiswa', 'projects.view', 'projects.create', 'projects.update');
        $program = Program::create(['name' => 'Teknik Informatika '.$programCode, 'code' => $programCode, 'degree' => 'S1', 'is_active' => true]);
        $term = AcademicTerm::create(['name' => 'Ganjil 2026 '.$programCode, 'code' => '2026-G-'.$programCode, 'semester' => 'Ganjil', 'starts_on' => '2026-08-01', 'ends_on' => '2027-01-31', 'is_active' => true]);
        $student = Student::create(['user_id' => $user->id, 'program_id' => $program->id, 'nim' => $nim, 'status' => 'Aktif', 'current_semester' => 7]);

        return compact('user', 'program', 'term', 'student');
    }

    private function user(string $role, string ...$permissions): User
    {
        $user = User::factory()->create(['active_role' => $role]);
        foreach ($permissions as $permission) Permission::findOrCreate($permission, 'web');
        $user->givePermissionTo($permissions);

        return $user;
    }

    private function payload(array $context): array
    {
        return ['academic_term_id' => $context['term']->id, 'project_type' => 'thesis', 'title' => 'Analisis Sistem Informasi Akademik Terpadu', 'abstract' => 'Penelitian tentang integrasi layanan akademik.', 'organization_name' => null, 'location' => 'Kampus Utama', 'starts_on' => '2026-08-10', 'ends_on' => '2027-01-20'];
    }
}
