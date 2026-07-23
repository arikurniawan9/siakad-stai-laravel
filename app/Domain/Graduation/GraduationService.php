<?php

namespace App\Domain\Graduation;

use App\Domain\Academic\GradeSheetService;
use App\Models\AlumniProfile;
use App\Models\CourseEnrollment;
use App\Models\GraduateDocument;
use App\Models\GraduateDocumentSequence;
use App\Models\GraduationApplication;
use App\Models\GraduationApplicationDocument;
use App\Models\GraduationPeriod;
use App\Models\Student;
use App\Models\StudentStatusHistory;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class GraduationService
{
    public function __construct(private readonly GradeSheetService $grades) {}

    public function start(Student $student, GraduationPeriod $period): GraduationApplication
    {
        if (! $period->is_active || now()->lt($period->registration_starts_at) || now()->gt($period->registration_ends_at)) throw ValidationException::withMessages(['period' => 'Pendaftaran periode yudisium/wisuda belum dibuka.']);
        return DB::transaction(function () use ($student, $period): GraduationApplication {
            $period = GraduationPeriod::query()->lockForUpdate()->findOrFail($period->id);
            return GraduationApplication::query()->firstOrCreate(['graduation_period_id' => $period->id, 'student_id' => $student->id], ['application_number' => 'GRD/'.now()->format('Y').'/'.strtoupper(substr((string) Str::ulid(), -10)), 'status' => 'draft']);
        }, 3);
    }

    public function storeDocument(GraduationApplication $application, string $type, UploadedFile $file, User $actor): GraduationApplicationDocument
    {
        if ($application->status !== 'draft') throw ValidationException::withMessages(['document' => 'Dokumen hanya dapat diperbarui saat pengajuan masih draf.']);
        $path = $file->store('graduation-applications/'.$application->id, 'local');
        try {
            return DB::transaction(function () use ($application, $type, $file, $path, $actor): GraduationApplicationDocument {
                $application = GraduationApplication::query()->lockForUpdate()->findOrFail($application->id);
                $current = $application->documents()->where('document_type', $type)->where('is_current', true)->lockForUpdate()->first();
                $version = (int) $application->documents()->where('document_type', $type)->max('version') + 1;
                $current?->update(['is_current' => false]);
                return $application->documents()->create(['document_type' => $type, 'version' => $version, 'disk' => 'local', 'path' => $path, 'original_name' => $file->getClientOriginalName(), 'mime_type' => $file->getMimeType() ?: 'application/octet-stream', 'size' => $file->getSize(), 'sha256' => hash_file('sha256', $file->getRealPath()), 'is_current' => true, 'uploaded_by' => $actor->id]);
            }, 3);
        } catch (\Throwable $exception) { Storage::disk('local')->delete($path); throw $exception; }
    }

    public function submit(GraduationApplication $application): GraduationApplication
    {
        return DB::transaction(function () use ($application): GraduationApplication {
            $application = GraduationApplication::query()->with(['student.program', 'period'])->lockForUpdate()->findOrFail($application->id);
            if ($application->status !== 'draft') throw ValidationException::withMessages(['application' => 'Pengajuan ini sudah dikirim.']);
            $types = $application->documents()->where('is_current', true)->pluck('document_type');
            $missing = collect(['identity', 'photo', 'clearance'])->diff($types);
            if ($missing->isNotEmpty()) throw ValidationException::withMessages(['documents' => 'Lengkapi dokumen identitas, pas foto, dan surat bebas administrasi.']);
            $snapshot = $this->eligibility($application->student);
            if (! $snapshot['eligible']) throw ValidationException::withMessages(['eligibility' => 'Syarat kelulusan belum terpenuhi: '.implode(', ', $snapshot['failures']).'.']);
            if ($application->period->quota && $application->period->applications()->whereIn('status', ['submitted', 'approved', 'graduated'])->count() >= $application->period->quota) throw ValidationException::withMessages(['period' => 'Kuota periode yudisium/wisuda telah penuh.']);
            $application->update(['status' => 'submitted', 'eligibility_snapshot' => $snapshot, 'submitted_at' => now()]);
            return $application->fresh();
        }, 3);
    }

    public function decide(GraduationApplication $application, string $decision, ?string $notes, User $actor): GraduationApplication
    {
        return DB::transaction(function () use ($application, $decision, $notes, $actor): GraduationApplication {
            $application = GraduationApplication::query()->with('student')->lockForUpdate()->findOrFail($application->id);
            if ($application->status !== 'submitted') throw ValidationException::withMessages(['application' => 'Hanya pengajuan terkirim yang dapat diperiksa.']);
            $snapshot = $this->eligibility($application->student);
            if ($decision === 'approve' && ! $snapshot['eligible']) throw ValidationException::withMessages(['eligibility' => 'Syarat kelulusan berubah dan tidak lagi terpenuhi.']);
            $application->update(['status' => $decision === 'approve' ? 'approved' : 'rejected', 'eligibility_snapshot' => $snapshot, 'review_notes' => filled($notes) ? trim((string) $notes) : null, 'reviewed_by' => $actor->id, 'reviewed_at' => now()]);
            return $application->fresh();
        }, 3);
    }

    public function markGraduated(GraduationApplication $application, User $actor): GraduationApplication
    {
        return DB::transaction(function () use ($application, $actor): GraduationApplication {
            $application = GraduationApplication::query()->with(['student.user', 'student.program', 'period'])->lockForUpdate()->findOrFail($application->id);
            if ($application->status === 'graduated') return $application;
            if ($application->status !== 'approved') throw ValidationException::withMessages(['application' => 'Peserta harus disetujui sebelum ditetapkan lulus.']);
            $snapshot = $this->graduateSnapshot($application);
            foreach (['diploma', 'final_transcript', 'skpi'] as $type) $this->issueDocument($application, $type, $snapshot, $actor);
            $student = Student::query()->lockForUpdate()->findOrFail($application->student_id); $from = $student->status; $student->update(['status' => 'Lulus']);
            StudentStatusHistory::create(['student_id' => $student->id, 'academic_term_id' => $application->period->academic_term_id, 'changed_by_user_id' => $actor->id, 'from_status' => $from, 'to_status' => 'Lulus', 'effective_on' => $application->period->judicium_on, 'reason' => 'Penetapan yudisium '.$application->period->name]);
            $application->update(['status' => 'graduated', 'graduated_at' => now()]);
            AlumniProfile::query()->firstOrCreate(['student_id' => $student->id], ['graduation_application_id' => $application->id, 'alumni_number' => 'ALM/'.$application->period->judicium_on->format('Y').'/'.str_pad((string) $student->id, 6, '0', STR_PAD_LEFT), 'personal_email' => $student->user?->email, 'phone' => $student->phone, 'address' => $student->address]);
            return $application->fresh(['graduateDocuments', 'alumniProfile']);
        }, 3);
    }

    public function eligibility(Student $student): array
    {
        $rows = CourseEnrollment::query()->where('status', 'enrolled')->whereIn('grade_status', ['published', 'finalized'])->whereNotNull('letter_grade')->whereHas('registration', fn (Builder $query) => $query->where('student_id', $student->id)->where('status', 'approved'))->get();
        $passedCredits = (int) $rows->filter(fn ($row) => $this->grades->pointsFor($row->letter_grade) > 0)->sum('credits'); $attempted = (int) $rows->sum('credits');
        $gpa = $attempted ? round((float) $rows->sum(fn ($row) => $this->grades->pointsFor($row->letter_grade) * $row->credits) / $attempted, 2) : 0.0;
        $minimumCredits = (int) config('siakad.graduation.minimum_credits', 144); $minimumGpa = (float) config('siakad.graduation.minimum_gpa', 2.0);
        $outstanding = (float) $student->billingItems()->whereIn('status', ['unpaid', 'partial'])->selectRaw('COALESCE(SUM(amount - paid_amount),0) total')->value('total');
        $projectComplete = ! config('siakad.graduation.require_completed_project', true) || $student->academicProjects()->where('status', 'completed')->whereHas('repository')->exists();
        $active = in_array(strtolower((string) $student->status), ['aktif', 'active'], true); $failures = [];
        if (! $active) $failures[] = 'status mahasiswa tidak aktif'; if ($passedCredits < $minimumCredits) $failures[] = "SKS lulus {$passedCredits}/{$minimumCredits}"; if ($gpa < $minimumGpa) $failures[] = "IPK {$gpa}/{$minimumGpa}"; if ($outstanding > 0.0001) $failures[] = 'masih memiliki tunggakan'; if (! $projectComplete) $failures[] = 'tugas akhir/repository belum selesai';
        return ['eligible' => $failures === [], 'passed_credits' => $passedCredits, 'minimum_credits' => $minimumCredits, 'gpa' => $gpa, 'minimum_gpa' => $minimumGpa, 'outstanding' => $outstanding, 'project_complete' => $projectComplete, 'student_status' => $student->status, 'failures' => $failures, 'checked_at' => now()->toIso8601String()];
    }

    private function graduateSnapshot(GraduationApplication $application): array
    {
        $student = $application->student; $student->loadMissing(['user', 'program']);
        $rows = CourseEnrollment::query()->with('classGroup.course')->where('status', 'enrolled')->whereIn('grade_status', ['published', 'finalized'])->whereHas('registration', fn ($q) => $q->where('student_id', $student->id)->where('status', 'approved'))->get();
        return ['institution' => config('siakad.institution'), 'student' => ['name' => $student->user?->name, 'nim' => $student->nim, 'program' => $student->program?->name, 'degree' => $student->program?->degree], 'graduation' => ['period' => $application->period->name, 'judicium_on' => $application->period->judicium_on->toDateString(), 'ceremony_on' => $application->period->ceremony_on?->toDateString()], 'eligibility' => $application->eligibility_snapshot, 'courses' => $rows->map(fn ($row) => ['code' => $row->classGroup->course->code, 'name' => $row->classGroup->course->name, 'credits' => (int) $row->credits, 'letter' => $row->letter_grade, 'score' => (float) $row->final_score])->all(), 'project' => $student->academicProjects()->where('status', 'completed')->latest()->first()?->only(['project_number', 'title', 'project_type'])];
    }

    private function issueDocument(GraduationApplication $application, string $type, array $baseSnapshot, User $actor): GraduateDocument
    {
        $existing = $application->graduateDocuments()->where('document_type', $type)->first(); if ($existing) return $existing;
        $year = (int) $application->period->judicium_on->format('Y'); $sequence = GraduateDocumentSequence::query()->where('year', $year)->where('document_type', $type)->lockForUpdate()->first();
        if (! $sequence) $sequence = GraduateDocumentSequence::create(['year' => $year, 'document_type' => $type, 'last_number' => 0]);
        $sequence->increment('last_number'); $number = strtr((string) config('siakad.graduation.document_number_format'), ['{TYPE}' => strtoupper($type), '{YEAR}' => (string) $year, '{SEQUENCE}' => str_pad((string) $sequence->last_number, (int) config('siakad.graduation.sequence_digits', 5), '0', STR_PAD_LEFT)]);
        $snapshot = [...$baseSnapshot, 'document_type' => $type, 'document_number' => $number]; $hash = hash('sha256', json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        return $application->graduateDocuments()->create(['document_type' => $type, 'document_number' => $number, 'verification_code' => (string) Str::ulid(), 'snapshot' => $snapshot, 'content_hash' => $hash, 'issued_by' => $actor->id, 'issued_at' => now()]);
    }
}
