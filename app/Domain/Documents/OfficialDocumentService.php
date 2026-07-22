<?php

namespace App\Domain\Documents;

use App\Domain\Academic\GradeSheetService;
use App\Models\BillingItem;
use App\Models\CourseEnrollment;
use App\Models\OfficialDocument;
use App\Models\Payment;
use App\Models\SemesterRegistration;
use App\Models\Student;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class OfficialDocumentService
{
    public const TYPES = ['krs', 'khs', 'transcript', 'invoice', 'receipt'];

    public function issue(string $type, int $sourceId, User $actor): OfficialDocument
    {
        [$source, $student] = $this->resolve($type, $sourceId);
        $this->authorize($actor, $type, $student, true);
        $snapshot = $this->snapshot($type, $source, $student);
        $hash = hash('sha256', json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));

        return DB::transaction(function () use ($type, $source, $student, $actor, $snapshot, $hash): OfficialDocument {
            $query = OfficialDocument::query()->where('type', $type)->where('source_type', $this->sourceType($type))->where('source_id', $source->getKey())->lockForUpdate();
            $existing = (clone $query)->whereNull('revoked_at')->where('content_hash', $hash)->first();
            if ($existing) return $existing;
            (clone $query)->whereNull('revoked_at')->update(['revoked_by' => $actor->id, 'revoked_at' => now(), 'revocation_reason' => 'Digantikan oleh versi dokumen yang lebih baru.', 'updated_at' => now()]);

            $verificationCode = (string) Str::ulid();

            return OfficialDocument::create([
                'document_number' => sprintf('%s/%s/%s/%s', Str::upper($type), now()->format('Y'), now()->format('md'), Str::upper(substr($verificationCode, -8))),
                'verification_code' => $verificationCode, 'type' => $type, 'student_id' => $student->id,
                'source_type' => $this->sourceType($type), 'source_id' => $source->getKey(), 'content_hash' => $hash,
                'snapshot' => $snapshot, 'issued_by' => $actor->id, 'issued_at' => now(),
            ]);
        }, 3);
    }

    public function revoke(OfficialDocument $document, User $actor, string $reason): OfficialDocument
    {
        abort_if($actor->active_role === 'Mahasiswa', 403);
        $this->authorize($actor, $document->type, $document->student()->firstOrFail(), true);
        if ($document->revoked_at) throw ValidationException::withMessages(['reason' => 'Dokumen ini sudah dicabut sebelumnya.']);
        $document->update(['revoked_by' => $actor->id, 'revoked_at' => now(), 'revocation_reason' => trim($reason)]);

        return $document->fresh();
    }

    public function authorize(User $user, string $type, Student $student, bool $issue = false): void
    {
        abort_unless(in_array($type, self::TYPES, true) && $user->can($issue ? 'documents.create' : 'documents.view'), 403);
        if ($user->active_role === 'Mahasiswa') abort_unless((int) $user->student?->id === (int) $student->id, 403);
        elseif ($user->active_role === 'Admin') return;
        elseif ($user->active_role === 'Prodi') abort_unless(in_array($type, ['krs', 'khs', 'transcript'], true), 403);
        elseif ($user->active_role === 'Dosen') abort_unless(! $issue && in_array($type, ['krs', 'khs', 'transcript'], true) && (int) $student->academic_advisor_id === (int) $user->lecturer?->id, 403);
        elseif (in_array($user->active_role, ['Keuangan', 'Bendahara'], true)) abort_unless(in_array($type, ['invoice', 'receipt'], true), 403);
        else abort(403);
    }

    /** @return array{0: Model, 1: Student} */
    public function resolve(string $type, int $sourceId): array
    {
        abort_unless(in_array($type, self::TYPES, true), 404);
        if (in_array($type, ['krs', 'khs'], true)) {
            $source = SemesterRegistration::query()->with('student')->findOrFail($sourceId);
            return [$source, $source->student];
        }
        if ($type === 'transcript') { $student = Student::query()->findOrFail($sourceId); return [$student, $student]; }
        if ($type === 'invoice') { $source = BillingItem::query()->with('student')->findOrFail($sourceId); abort_unless($source->student, 404); return [$source, $source->student]; }
        $source = Payment::query()->with('student')->findOrFail($sourceId); abort_unless($source->student, 404); return [$source, $source->student];
    }

    private function snapshot(string $type, Model $source, Student $student): array
    {
        $student->loadMissing(['user:id,name,email', 'program:id,faculty_id,code,name,degree', 'program.faculty:id,campus_id,code,name', 'program.faculty.campus:id,name,address', 'academicAdvisor:id,name,nidn']);
        $identity = [
            'name' => $student->user?->name ?? '-', 'nim' => $student->nim ?? '-', 'program' => $student->program?->name ?? '-',
            'program_code' => $student->program?->code ?? '-', 'degree' => $student->program?->degree ?? '-', 'faculty' => $student->program?->faculty?->name ?? '-',
            'advisor' => $student->academicAdvisor?->name, 'advisor_nidn' => $student->academicAdvisor?->nidn,
        ];
        $institution = ['name' => config('siakad.institution'), 'campus' => $student->program?->faculty?->campus?->name, 'address' => $student->program?->faculty?->campus?->address];
        $base = ['type' => $type, 'institution' => $institution, 'student' => $identity];
        if ($type === 'krs') return [...$base, ...$this->academicRegistrationSnapshot($source, false)];
        if ($type === 'khs') return [...$base, ...$this->academicRegistrationSnapshot($source, true)];
        if ($type === 'transcript') return [...$base, ...$this->transcriptSnapshot($student)];
        if ($type === 'invoice') return [...$base, ...$this->invoiceSnapshot($source)];
        return [...$base, ...$this->receiptSnapshot($source)];
    }

    private function academicRegistrationSnapshot(SemesterRegistration $registration, bool $withGrades): array
    {
        if ($registration->status !== 'approved') throw ValidationException::withMessages(['document' => 'Dokumen akademik resmi hanya dapat diterbitkan untuk KRS yang disetujui.']);
        $registration->load(['academicTerm:id,code,name,semester', 'reviewedBy:id,name', 'enrollments' => fn ($query) => $query->where('status', 'enrolled')->with(['classGroup.course:id,code,name,credits', 'classGroup.lecturer:id,name,nidn'])]);
        if ($registration->enrollments->isEmpty()) throw ValidationException::withMessages(['document' => 'Registrasi ini belum memiliki mata kuliah aktif.']);
        if ($withGrades && $registration->enrollments->contains(fn (CourseEnrollment $row) => ! in_array($row->grade_status, ['published', 'finalized'], true))) {
            throw ValidationException::withMessages(['document' => 'KHS resmi baru dapat diterbitkan setelah seluruh nilai dipublikasikan.']);
        }
        $gradeService = app(GradeSheetService::class);
        $courses = $registration->enrollments->map(fn (CourseEnrollment $row): array => [
            'code' => $row->classGroup->course->code, 'name' => $row->classGroup->course->name, 'class' => $row->classGroup->name,
            'credits' => (int) $row->credits, 'lecturer' => $row->classGroup->lecturer?->name,
            ...($withGrades ? ['score' => (float) $row->final_score, 'letter' => $row->letter_grade, 'points' => $gradeService->pointsFor($row->letter_grade)] : []),
        ])->values()->all();
        $credits = collect($courses)->sum('credits');
        $gpa = $withGrades && $credits ? round(collect($courses)->sum(fn ($row) => $row['points'] * $row['credits']) / $credits, 2) : null;
        return ['title' => $withGrades ? 'Kartu Hasil Studi' : 'Kartu Rencana Studi', 'term' => ['code' => $registration->academicTerm->code, 'name' => $registration->academicTerm->name, 'semester' => $registration->academicTerm->semester], 'courses' => $courses, 'summary' => ['courses' => count($courses), 'credits' => $credits, 'gpa' => $gpa], 'approved_by' => $registration->reviewedBy?->name, 'approved_at' => $registration->reviewed_at?->toIso8601String()];
    }

    private function transcriptSnapshot(Student $student): array
    {
        $rows = CourseEnrollment::query()->with(['classGroup.course:id,code,name', 'classGroup.academicTerm:id,code,name,semester,starts_on'])
            ->where('status', 'enrolled')->whereIn('grade_status', ['published', 'finalized'])
            ->whereHas('registration', fn ($query) => $query->where('student_id', $student->id)->where('status', 'approved'))->get()
            ->sortBy(fn (CourseEnrollment $row) => ($row->classGroup->academicTerm->starts_on?->format('Ymd') ?? '').$row->classGroup->course->code)->values();
        if ($rows->isEmpty()) throw ValidationException::withMessages(['document' => 'Transkrip belum dapat diterbitkan karena belum ada nilai yang dipublikasikan.']);
        $gradeService = app(GradeSheetService::class);
        $courses = $rows->map(fn (CourseEnrollment $row): array => ['term' => $row->classGroup->academicTerm->code, 'code' => $row->classGroup->course->code, 'name' => $row->classGroup->course->name, 'credits' => (int) $row->credits, 'score' => (float) $row->final_score, 'letter' => $row->letter_grade, 'points' => $gradeService->pointsFor($row->letter_grade)])->all();
        $credits = collect($courses)->sum('credits');
        return ['title' => 'Transkrip Nilai Akademik', 'courses' => $courses, 'summary' => ['courses' => count($courses), 'credits' => $credits, 'gpa' => $credits ? round(collect($courses)->sum(fn ($row) => $row['points'] * $row['credits']) / $credits, 2) : 0]];
    }

    private function invoiceSnapshot(BillingItem $bill): array
    {
        $bill->loadMissing('academicTerm:id,code,name,semester');
        return ['title' => 'Tagihan Pendidikan', 'invoice' => ['number' => $bill->invoice_number, 'description' => $bill->description, 'category' => $bill->category, 'amount' => (float) $bill->amount, 'paid_amount' => (float) $bill->paid_amount, 'outstanding' => max(0, (float) $bill->amount - (float) $bill->paid_amount), 'due_on' => $bill->due_on?->toDateString(), 'status' => $bill->status], 'term' => $bill->academicTerm ? ['code' => $bill->academicTerm->code, 'name' => $bill->academicTerm->name, 'semester' => $bill->academicTerm->semester] : null];
    }

    private function receiptSnapshot(Payment $payment): array
    {
        if (in_array($payment->status, ['failed', 'reversed'], true)) throw ValidationException::withMessages(['document' => 'Kwitansi tidak dapat diterbitkan untuk pembayaran gagal atau dibatalkan.']);
        $payment->loadMissing('allocations.billingItem:id,invoice_number,description');
        return ['title' => 'Kwitansi Pembayaran', 'payment' => ['reference' => $payment->external_reference, 'provider' => $payment->provider, 'amount' => (float) $payment->amount, 'currency' => $payment->currency, 'paid_at' => $payment->paid_at?->toIso8601String(), 'status' => $payment->status, 'allocations' => $payment->allocations->map(fn ($row) => ['invoice_number' => $row->billingItem?->invoice_number, 'description' => $row->billingItem?->description, 'amount' => (float) $row->amount])->all()]];
    }

    private function sourceType(string $type): string
    {
        return match ($type) { 'krs', 'khs' => 'semester_registration', 'transcript' => 'student', 'invoice' => 'billing_item', 'receipt' => 'payment' };
    }
}
