<?php

namespace App\Domain\Services;

use App\Models\ServiceRequestType;
use App\Models\Student;
use App\Models\StudentServiceRequest;
use App\Models\StudentServiceRequestStep;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class StudentServiceWorkflow
{
    public const STAGES = ['advisor', 'program', 'finance', 'academic'];

    public function __construct(private readonly StudentServiceDocumentService $documents, private readonly NotificationService $notifications) {}

    public function normalizeWorkflow(array $workflow): array
    {
        $selected = array_values(array_unique(array_intersect(self::STAGES, $workflow)));
        if ($selected === []) throw ValidationException::withMessages(['workflow' => 'Pilih minimal satu tahap persetujuan.']);
        return $selected;
    }

    public function submit(Student $student, ServiceRequestType $type, array $data, array $attachment, User $actor): StudentServiceRequest
    {
        if ($student->status !== 'Aktif') throw ValidationException::withMessages(['service_request_type_id' => 'Hanya mahasiswa aktif yang dapat mengajukan layanan.']);
        if (! $type->is_active || $type->trashed()) throw ValidationException::withMessages(['service_request_type_id' => 'Jenis layanan sedang tidak aktif.']);
        if ($type->requires_attachment && empty($attachment['attachment_path'])) throw ValidationException::withMessages(['attachment' => 'Jenis layanan ini mewajibkan lampiran pendukung.']);
        $workflow = $this->normalizeWorkflow($type->workflow ?? []);
        if (in_array('advisor', $workflow, true) && ! $student->academic_advisor_id) throw ValidationException::withMessages(['service_request_type_id' => 'Dosen PA belum ditetapkan sehingga layanan ini belum dapat diajukan.']);

        $request = DB::transaction(function () use ($student, $type, $data, $attachment, $actor, $workflow): StudentServiceRequest {
            $token = (string) Str::ulid();
            $request = StudentServiceRequest::create(['request_number' => sprintf('LAY/%s/%s', now()->format('Y'), Str::upper(substr($token, -10))), 'student_id' => $student->id, 'service_request_type_id' => $type->id, 'subject' => trim($data['subject']), 'purpose' => trim($data['purpose']), 'details' => ['additional_information' => filled($data['additional_information'] ?? null) ? trim($data['additional_information']) : null], ...$attachment, 'status' => 'in_review', 'current_stage' => $workflow[0], 'submitted_at' => now(), 'due_at' => now()->addWeekdays($type->sla_business_days), 'last_action_by' => $actor->id]);
            foreach ($workflow as $index => $stage) $request->steps()->create(['sequence' => $index + 1, 'stage' => $stage, 'status' => $index === 0 ? 'pending' : 'waiting']);
            $this->event($request, $actor, 'submitted', null, 'in_review', $workflow[0], 'Pengajuan layanan dikirim.');
            return $request;
        }, 3);
        $this->notifications->student($student, 'service', 'Pengajuan layanan diterima', $type->name.' dengan nomor '.$request->request_number.' sedang diperiksa.', '/services?selected='.$request->id);
        $this->notifyReviewers($request->fresh(['student.academicAdvisor.user', 'type']), $workflow[0]);
        return $request;
    }

    public function decide(StudentServiceRequest $request, string $decision, ?string $notes, User $actor): StudentServiceRequest
    {
        $result = DB::transaction(function () use ($request, $decision, $notes, $actor): array {
            $request = StudentServiceRequest::query()->lockForUpdate()->with(['student.academicAdvisor.user', 'type'])->findOrFail($request->id);
            if ($request->status !== 'in_review') throw ValidationException::withMessages(['decision' => 'Pengajuan tidak sedang menunggu persetujuan.']);
            $step = $request->steps()->where('stage', $request->current_stage)->lockForUpdate()->firstOrFail();
            if ($step->status !== 'pending') throw ValidationException::withMessages(['decision' => 'Tahap ini sudah diproses.']);
            abort_unless($this->canReview($actor, $request, $step), 403);
            $old = $request->status; $trimmed = filled($notes) ? trim((string) $notes) : null;
            if ($decision === 'revision') {
                $step->update(['status' => 'revision_required', 'decided_by' => $actor->id, 'decided_at' => now(), 'decision_notes' => $trimmed]);
                $request->update(['status' => 'revision_required', 'last_action_by' => $actor->id]);
                $this->event($request, $actor, 'revision_requested', $old, 'revision_required', $step->stage, $trimmed);
                return [$request->fresh(), null, 'revision'];
            }
            if ($decision === 'reject') {
                $step->update(['status' => 'rejected', 'decided_by' => $actor->id, 'decided_at' => now(), 'decision_notes' => $trimmed]);
                $request->update(['status' => 'rejected', 'last_action_by' => $actor->id]);
                $this->event($request, $actor, 'rejected', $old, 'rejected', $step->stage, $trimmed);
                return [$request->fresh(), null, 'reject'];
            }
            if ($step->stage === 'finance' && $request->type->requires_financial_clearance) {
                $outstanding = (float) $request->student->billingItems()->whereIn('status', ['unpaid', 'partial'])->sum(DB::raw('amount - paid_amount'));
                if ($outstanding > 0) throw ValidationException::withMessages(['decision' => 'Persetujuan keuangan ditolak sistem karena mahasiswa masih memiliki tagihan aktif sebesar Rp '.number_format($outstanding, 0, ',', '.').'.']);
            }
            $step->update(['status' => 'approved', 'decided_by' => $actor->id, 'decided_at' => now(), 'decision_notes' => $trimmed]);
            $next = $request->steps()->where('sequence', '>', $step->sequence)->orderBy('sequence')->lockForUpdate()->first();
            if ($next) {
                $next->update(['status' => 'pending']); $request->update(['current_stage' => $next->stage, 'last_action_by' => $actor->id]);
                $this->event($request, $actor, 'stage_approved', $old, 'in_review', $step->stage, $trimmed, ['next_stage' => $next->stage]);
                return [$request->fresh(), $next->stage, 'next'];
            }
            $request->update(['status' => 'completed', 'current_stage' => null, 'completed_at' => now(), 'last_action_by' => $actor->id]);
            $this->event($request, $actor, 'completed', $old, 'completed', $step->stage, $trimmed);
            $this->documents->issue($request->fresh(['type', 'student', 'steps.decider']), $actor);
            return [$request->fresh(), null, 'complete'];
        }, 3);
        [$updated, $nextStage, $outcome] = $result; $updated->loadMissing(['student', 'type']);
        $message = match ($outcome) { 'revision' => 'Pengajuan memerlukan perbaikan: '.($notes ?: '-'), 'reject' => 'Pengajuan ditolak: '.($notes ?: '-'), 'complete' => 'Seluruh persetujuan selesai dan surat resmi telah diterbitkan.', default => 'Satu tahap disetujui dan diteruskan ke pemeriksa berikutnya.' };
        $this->notifications->student($updated->student, 'service', $outcome === 'complete' ? 'Surat layanan telah terbit' : 'Status layanan diperbarui', $updated->type->name.': '.$message, '/services?selected='.$updated->id);
        if ($nextStage) $this->notifyReviewers($updated, $nextStage);
        return $updated;
    }

    public function resubmit(StudentServiceRequest $request, array $data, array $attachment, User $actor): StudentServiceRequest
    {
        $updated = DB::transaction(function () use ($request, $data, $attachment, $actor): StudentServiceRequest {
            $request = StudentServiceRequest::query()->lockForUpdate()->findOrFail($request->id);
            abort_unless((int) $request->student_id === (int) $actor->student?->id, 403);
            if ($request->status !== 'revision_required') throw ValidationException::withMessages(['request' => 'Pengajuan ini tidak sedang menunggu revisi.']);
            $step = $request->steps()->where('stage', $request->current_stage)->lockForUpdate()->firstOrFail();
            if ($step->status !== 'revision_required') throw ValidationException::withMessages(['request' => 'Tahap revisi tidak valid.']);
            $type = $request->type()->firstOrFail(); $hasAttachment = ! empty($attachment['attachment_path']) || filled($request->attachment_path);
            if ($type->requires_attachment && ! $hasAttachment) throw ValidationException::withMessages(['attachment' => 'Lampiran wajib tetap harus tersedia.']);
            $request->update(['subject' => trim($data['subject']), 'purpose' => trim($data['purpose']), 'details' => ['additional_information' => filled($data['additional_information'] ?? null) ? trim($data['additional_information']) : null], ...$attachment, 'status' => 'in_review', 'revision_number' => $request->revision_number + 1, 'submitted_at' => now(), 'due_at' => now()->addWeekdays($type->sla_business_days), 'last_action_by' => $actor->id]);
            $step->update(['status' => 'pending']); $this->event($request, $actor, 'resubmitted', 'revision_required', 'in_review', $step->stage, 'Mahasiswa mengirim revisi pengajuan.', ['revision_number' => $request->revision_number]);
            return $request->fresh(['student.academicAdvisor.user', 'type']);
        }, 3);
        $this->notifications->student($updated->student, 'service', 'Revisi layanan dikirim', $updated->type->name.' kembali masuk antrean pemeriksaan.', '/services?selected='.$updated->id);
        $this->notifyReviewers($updated, (string) $updated->current_stage);
        return $updated;
    }

    public function cancel(StudentServiceRequest $request, User $actor): StudentServiceRequest
    {
        return DB::transaction(function () use ($request, $actor): StudentServiceRequest {
            $request = StudentServiceRequest::query()->lockForUpdate()->findOrFail($request->id); abort_unless((int) $request->student_id === (int) $actor->student?->id, 403);
            if (! in_array($request->status, ['in_review', 'revision_required'], true)) throw ValidationException::withMessages(['request' => 'Pengajuan yang sudah selesai tidak dapat dibatalkan.']);
            $old = $request->status; $request->update(['status' => 'cancelled', 'current_stage' => null, 'cancelled_at' => now(), 'last_action_by' => $actor->id]); $this->event($request, $actor, 'cancelled', $old, 'cancelled', null, 'Pengajuan dibatalkan mahasiswa.');
            return $request->fresh();
        }, 3);
    }

    public function canReview(User $user, StudentServiceRequest $request, ?StudentServiceRequestStep $step = null): bool
    {
        if (! $user->can('service_requests.update')) return false;
        $stage = $step?->stage ?? $request->current_stage;
        if ($user->active_role === 'Admin') return true;
        if ($stage === 'advisor' && $user->active_role === 'Dosen') return (int) $request->student->academic_advisor_id === (int) $user->lecturer?->id;
        if ($stage === 'program') return $user->active_role === 'Prodi';
        if ($stage === 'finance') return in_array($user->active_role, ['Keuangan', 'Bendahara'], true);
        return $stage === 'academic' && $user->active_role === 'Staff';
    }

    private function event(StudentServiceRequest $request, ?User $actor, string $action, ?string $from, ?string $to, ?string $stage, ?string $notes, array $metadata = []): void
    {
        $request->events()->create(['actor_id' => $actor?->id, 'action' => $action, 'from_status' => $from, 'to_status' => $to, 'stage' => $stage, 'notes' => $notes, 'metadata' => $metadata ?: null, 'created_at' => now()]);
    }

    private function notifyReviewers(StudentServiceRequest $request, string $stage): void
    {
        $userIds = match ($stage) {
            'advisor' => collect([$request->student->academicAdvisor?->user_id])->filter(),
            'program' => User::role('Prodi')->where('is_active', true)->pluck('id'),
            'finance' => User::role(['Keuangan', 'Bendahara'])->where('is_active', true)->pluck('id'),
            'academic' => User::role(['Staff', 'Admin'])->where('is_active', true)->pluck('id'),
            default => collect(),
        };
        foreach ($userIds->unique() as $id) $this->notifications->send((int) $id, 'service', 'Pengajuan layanan menunggu pemeriksaan', $request->request_number.' · '.$request->type->name, '/services?selected='.$request->id, ['service_request_id' => $request->id, 'stage' => $stage]);
    }
}
