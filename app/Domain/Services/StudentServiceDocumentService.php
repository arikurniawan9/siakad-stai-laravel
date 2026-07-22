<?php

namespace App\Domain\Services;

use App\Models\StudentServiceDocument;
use App\Models\StudentServiceRequest;
use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class StudentServiceDocumentService
{
    public function issue(StudentServiceRequest $request, User $actor): StudentServiceDocument
    {
        if ($request->status !== 'completed') throw ValidationException::withMessages(['document' => 'Surat hanya dapat diterbitkan setelah seluruh persetujuan selesai.']);
        if ($existing = $request->document()->first()) return $existing;
        $request->loadMissing(['type', 'student.user:id,name,email', 'student.program:id,faculty_id,code,name,degree', 'student.program.faculty:id,campus_id,code,name', 'student.program.faculty.campus:id,name,address', 'student.academicAdvisor:id,name,nidn', 'steps.decider:id,name']);
        $student = $request->student; $type = $request->type;
        $replacements = ['{NAMA}' => $student->user?->name ?? '-', '{NIM}' => $student->nim ?? '-', '{PROGRAM}' => $student->program?->name ?? '-', '{TUJUAN}' => $request->purpose];
        $snapshot = [
            'institution' => ['name' => config('siakad.institution'), 'campus' => $student->program?->faculty?->campus?->name, 'address' => $student->program?->faculty?->campus?->address],
            'student' => ['name' => $student->user?->name ?? '-', 'nim' => $student->nim ?? '-', 'program' => $student->program?->name ?? '-', 'program_code' => $student->program?->code ?? '-', 'degree' => $student->program?->degree ?? '-', 'faculty' => $student->program?->faculty?->name ?? '-', 'advisor' => $student->academicAdvisor?->name],
            'request' => ['number' => $request->request_number, 'type_code' => $type->code, 'type_name' => $type->name, 'category' => $type->category, 'subject' => $request->subject, 'purpose' => $request->purpose, 'additional_information' => $request->details['additional_information'] ?? null, 'submitted_at' => $request->submitted_at?->toIso8601String(), 'completed_at' => $request->completed_at?->toIso8601String(), 'revision_number' => $request->revision_number],
            'letter' => ['subject' => strtr($type->template_subject, $replacements), 'body' => strtr($type->template_body, $replacements)],
            'approvals' => $request->steps->map(fn ($step) => ['stage' => $step->stage, 'status' => $step->status, 'name' => $step->decider?->name, 'decided_at' => $step->decided_at?->toIso8601String()])->all(),
        ];
        $hash = hash('sha256', json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION)); $verificationCode = (string) Str::ulid();

        return $request->document()->create(['document_number' => sprintf('SRV/%s/%s/%s', Str::upper($type->code), now()->format('Y'), Str::upper(substr($verificationCode, -8))), 'verification_code' => $verificationCode, 'content_hash' => $hash, 'snapshot' => $snapshot, 'issued_by' => $actor->id, 'issued_at' => now()]);
    }

    public function revoke(StudentServiceDocument $document, User $actor, string $reason): StudentServiceDocument
    {
        abort_unless($actor->active_role === 'Admin' && $actor->can('service_requests.delete'), 403);
        if ($document->revoked_at) throw ValidationException::withMessages(['reason' => 'Surat ini sudah dicabut sebelumnya.']);
        $document->update(['revoked_by' => $actor->id, 'revoked_at' => now(), 'revocation_reason' => trim($reason)]);
        return $document->fresh();
    }

    public function integrityValid(StudentServiceDocument $document): bool
    {
        $hash = hash('sha256', json_encode($document->snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));
        return hash_equals($document->content_hash, $hash);
    }
}
