<?php

namespace App\Domain\Projects;

use App\Domain\Academic\GradeSheetService;
use App\Models\AcademicProject;
use App\Models\AcademicProjectDocument;
use App\Models\CourseEnrollment;
use App\Models\Student;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class AcademicProjectService
{
    public function __construct(private readonly GradeSheetService $grades) {}

    public function create(Student $student, array $data, User $actor): AcademicProject
    {
        return DB::transaction(function () use ($student, $data, $actor): AcademicProject {
            $existing = AcademicProject::query()->where('student_id', $student->id)->where('project_type', $data['project_type'])->whereNotIn('status', ['rejected', 'completed'])->lockForUpdate()->first();
            if ($existing) throw ValidationException::withMessages(['project_type' => 'Masih ada pengajuan jenis ini yang belum selesai.']);

            return AcademicProject::create([
                ...$data,
                'project_number' => 'PRJ/'.now()->format('Y').'/'.strtoupper(substr($data['project_type'], 0, 3)).'/'.strtoupper(substr((string) Str::ulid(), -8)),
                'student_id' => $student->id,
                'program_id' => $student->program_id,
                'status' => 'draft',
                'created_by' => $actor->id,
            ]);
        }, 3);
    }

    public function update(AcademicProject $project, array $data): AcademicProject
    {
        return DB::transaction(function () use ($project, $data): AcademicProject {
            $project = AcademicProject::query()->lockForUpdate()->findOrFail($project->id);
            if (! in_array($project->status, ['draft', 'revision_required'], true)) throw ValidationException::withMessages(['project' => 'Pengajuan yang sedang diperiksa tidak dapat diubah.']);
            if ($project->project_type !== $data['project_type']) throw ValidationException::withMessages(['project_type' => 'Jenis kegiatan tidak dapat diubah setelah pengajuan dibuat.']);
            $project->update($data);

            return $project->fresh();
        }, 3);
    }

    public function storeDocument(AcademicProject $project, string $type, UploadedFile $file, User $actor): AcademicProjectDocument
    {
        $allowedTypes = $project->status === 'active' ? ['supporting', 'revision', 'final_report', 'repository'] : ['proposal', 'eligibility', 'supporting', 'revision'];
        if (! in_array($project->status, ['draft', 'revision_required', 'active'], true) || ! in_array($type, $allowedTypes, true)) throw ValidationException::withMessages(['document' => 'Jenis dokumen tidak dapat diunggah pada tahap kegiatan saat ini.']);
        $path = $file->store('academic-projects/'.$project->id, 'local');

        try {
            return DB::transaction(function () use ($project, $type, $file, $path, $actor): AcademicProjectDocument {
                $project = AcademicProject::query()->lockForUpdate()->findOrFail($project->id);
                $current = $project->documents()->where('document_type', $type)->where('is_current', true)->lockForUpdate()->first();
                $version = (int) $project->documents()->where('document_type', $type)->max('version') + 1;
                $current?->update(['is_current' => false]);

                return $project->documents()->create([
                    'document_type' => $type, 'version' => $version, 'disk' => 'local', 'path' => $path,
                    'original_name' => $file->getClientOriginalName(), 'mime_type' => $file->getMimeType() ?: 'application/octet-stream',
                    'size' => $file->getSize(), 'sha256' => hash_file('sha256', $file->getRealPath()), 'is_current' => true, 'uploaded_by' => $actor->id,
                ]);
            }, 3);
        } catch (\Throwable $exception) {
            Storage::disk('local')->delete($path);
            throw $exception;
        }
    }

    public function submit(AcademicProject $project, User $actor): AcademicProject
    {
        return DB::transaction(function () use ($project, $actor): AcademicProject {
            $project = AcademicProject::query()->with('student')->lockForUpdate()->findOrFail($project->id);
            if (! in_array($project->status, ['draft', 'revision_required'], true)) throw ValidationException::withMessages(['project' => 'Status pengajuan tidak dapat dikirim.']);
            if (! $project->documents()->where('document_type', 'proposal')->where('is_current', true)->exists()) throw ValidationException::withMessages(['document' => 'Dokumen proposal wajib diunggah sebelum pengajuan dikirim.']);
            $snapshot = $this->eligibility($project->student, $project->project_type);
            if (! $snapshot['eligible']) throw ValidationException::withMessages(['eligibility' => 'Syarat akademik belum terpenuhi: '.implode(', ', $snapshot['failures']).'.']);
            $project->update(['status' => 'submitted', 'eligibility_snapshot' => $snapshot, 'submitted_at' => now(), 'review_notes' => null, 'reviewed_by' => null, 'reviewed_at' => null]);

            return $project->fresh();
        }, 3);
    }

    public function decide(AcademicProject $project, string $decision, ?string $notes, User $actor): AcademicProject
    {
        return DB::transaction(function () use ($project, $decision, $notes, $actor): AcademicProject {
            $project = AcademicProject::query()->lockForUpdate()->findOrFail($project->id);
            if ($project->status !== 'submitted') throw ValidationException::withMessages(['project' => 'Hanya pengajuan terkirim yang dapat diperiksa.']);
            $status = ['approve' => 'approved', 'revision' => 'revision_required', 'reject' => 'rejected'][$decision];
            $project->update(['status' => $status, 'review_notes' => filled($notes) ? trim((string) $notes) : null, 'reviewed_by' => $actor->id, 'reviewed_at' => now()]);

            return $project->fresh();
        }, 3);
    }

    public function syncAssignments(AcademicProject $project, array $data, User $actor): AcademicProject
    {
        return DB::transaction(function () use ($project, $data, $actor): AcademicProject {
            $project = AcademicProject::query()->lockForUpdate()->findOrFail($project->id);
            if (! in_array($project->status, ['approved', 'active'], true)) throw ValidationException::withMessages(['project' => 'Pembimbing dan penguji hanya dapat ditetapkan setelah proposal disetujui.']);
            if ($project->defenses()->exists()) throw ValidationException::withMessages(['assignments' => 'Tim akademik terkunci setelah seminar atau sidang dijadwalkan.']);
            $rows = collect();
            foreach ($data['supervisor_ids'] as $index => $id) $rows->push(['lecturer_id' => (int) $id, 'role' => 'supervisor', 'sequence' => $index + 1]);
            foreach ($data['examiner_ids'] as $index => $id) $rows->push(['lecturer_id' => (int) $id, 'role' => 'examiner', 'sequence' => $index + 1]);
            if ($rows->pluck('lecturer_id')->duplicates()->isNotEmpty()) throw ValidationException::withMessages(['assignments' => 'Dosen tidak boleh memiliki dua peran pada kegiatan yang sama.']);
            $project->lecturerAssignments()->delete();
            foreach ($rows as $row) $project->lecturerAssignments()->create([...$row, 'assigned_by' => $actor->id, 'assigned_at' => now()]);
            if ($project->status === 'approved') $project->update(['status' => 'active']);

            return $project->fresh(['lecturerAssignments.lecturer.user']);
        }, 3);
    }

    public function eligibility(Student $student, string $type): array
    {
        $rows = CourseEnrollment::query()->where('status', 'enrolled')->whereIn('grade_status', ['published', 'finalized'])->whereNotNull('letter_grade')->whereHas('registration', fn (Builder $query) => $query->where('student_id', $student->id)->where('status', 'approved'))->get();
        $credits = (int) $rows->filter(fn (CourseEnrollment $row) => $this->grades->pointsFor($row->letter_grade) > 0)->sum('credits');
        $attempted = (int) $rows->sum('credits');
        $gpa = $attempted > 0 ? round((float) $rows->sum(fn (CourseEnrollment $row) => $this->grades->pointsFor($row->letter_grade) * $row->credits) / $attempted, 2) : 0.0;
        $minimumCredits = (int) config('siakad.projects.minimum_credits.'.$type, 0);
        $minimumGpa = (float) config('siakad.projects.minimum_gpa', 2.0);
        $active = in_array(strtolower((string) $student->status), ['aktif', 'active'], true);
        $failures = [];
        if (! $active) $failures[] = 'status mahasiswa tidak aktif';
        if ($credits < $minimumCredits) $failures[] = "SKS lulus {$credits}/{$minimumCredits}";
        if ($gpa < $minimumGpa) $failures[] = "IPK {$gpa}/{$minimumGpa}";

        return ['eligible' => $failures === [], 'student_status' => $student->status, 'passed_credits' => $credits, 'minimum_credits' => $minimumCredits, 'gpa' => $gpa, 'minimum_gpa' => $minimumGpa, 'failures' => $failures, 'checked_at' => now()->toIso8601String()];
    }
}
