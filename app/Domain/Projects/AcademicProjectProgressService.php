<?php

namespace App\Domain\Projects;

use App\Models\AcademicProject;
use App\Models\AcademicProjectDefense;
use App\Models\AcademicProjectLogbook;
use App\Models\AcademicProjectRepository;
use App\Models\AcademicProjectRubricItem;
use App\Models\AcademicProjectScore;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class AcademicProjectProgressService
{
    public function createLogbook(AcademicProject $project, array $data, User $actor): AcademicProjectLogbook
    {
        return DB::transaction(function () use ($project, $data, $actor): AcademicProjectLogbook {
            $this->lockActiveProject($project);
            return $project->logbooks()->create([...$data, 'status' => 'submitted', 'created_by' => $actor->id]);
        }, 3);
    }

    public function reviewLogbook(AcademicProject $project, AcademicProjectLogbook $logbook, string $decision, ?string $notes, User $actor): AcademicProjectLogbook
    {
        return DB::transaction(function () use ($project, $logbook, $decision, $notes, $actor): AcademicProjectLogbook {
            $this->lockActiveProject($project);
            $logbook = $project->logbooks()->lockForUpdate()->findOrFail($logbook->id);
            if ($logbook->status !== 'submitted') throw ValidationException::withMessages(['logbook' => 'Logbook ini sudah diperiksa.']);
            $logbook->update(['status' => $decision === 'verify' ? 'verified' : 'revision_required', 'supervisor_notes' => filled($notes) ? trim((string) $notes) : null, 'reviewed_by' => $actor->id, 'reviewed_at' => now()]);
            return $logbook->fresh();
        }, 3);
    }

    public function createGuidance(AcademicProject $project, array $data, User $actor): object
    {
        return DB::transaction(function () use ($project, $data, $actor): object {
            $this->lockActiveProject($project);
            return $project->guidanceRecords()->create([...$data, 'lecturer_id' => $actor->lecturer->id, 'created_by' => $actor->id]);
        }, 3);
    }

    public function scheduleDefense(AcademicProject $project, array $data, User $actor): AcademicProjectDefense
    {
        return DB::transaction(function () use ($project, $data, $actor): AcademicProjectDefense {
            $project = $this->lockActiveProject($project)->load('lecturerAssignments');
            if (! $project->lecturerAssignments->contains('role', 'supervisor')) throw ValidationException::withMessages(['project' => 'Tetapkan pembimbing sebelum menjadwalkan seminar atau sidang.']);
            if (in_array($data['defense_type'], ['final_seminar', 'defense'], true) && ! $project->lecturerAssignments->contains('role', 'examiner')) throw ValidationException::withMessages(['project' => 'Tetapkan minimal satu penguji sebelum menjadwalkan seminar akhir atau sidang.']);
            if ($project->defenses()->where('defense_type', $data['defense_type'])->exists()) throw ValidationException::withMessages(['defense_type' => 'Jenis seminar/sidang ini sudah memiliki jadwal.']);
            $this->assertNoConflict($project, $data);
            return $project->defenses()->create([...$data, 'status' => 'scheduled', 'verification_code' => (string) Str::ulid(), 'created_by' => $actor->id]);
        }, 3);
    }

    public function saveRubric(AcademicProject $project, AcademicProjectDefense $defense, array $items): Collection
    {
        return DB::transaction(function () use ($project, $defense, $items): Collection {
            $this->lockActiveProject($project);
            $defense = $project->defenses()->lockForUpdate()->findOrFail($defense->id);
            if ($defense->status !== 'scheduled' || $defense->scores()->exists()) throw ValidationException::withMessages(['rubric' => 'Rubrik tidak dapat diubah setelah penilaian dimulai atau sidang selesai.']);
            if (abs((float) collect($items)->sum(fn ($item) => (float) $item['weight']) - 100) > 0.001) throw ValidationException::withMessages(['rubric' => 'Total bobot rubrik harus tepat 100%.']);
            $defense->rubricItems()->delete();
            foreach ($items as $index => $item) $defense->rubricItems()->create([...$item, 'sort_order' => $index + 1]);
            return $defense->rubricItems()->orderBy('sort_order')->get();
        }, 3);
    }

    public function saveScores(AcademicProject $project, AcademicProjectDefense $defense, array $scores, User $actor): Collection
    {
        return DB::transaction(function () use ($project, $defense, $scores, $actor): Collection {
            $this->lockActiveProject($project);
            $defense = $project->defenses()->lockForUpdate()->findOrFail($defense->id);
            if ($defense->status !== 'scheduled') throw ValidationException::withMessages(['scores' => 'Penilaian hanya tersedia untuk seminar/sidang yang terjadwal.']);
            $items = $defense->rubricItems()->lockForUpdate()->get()->keyBy('id');
            if ($items->isEmpty() || $items->keys()->diff(collect($scores)->pluck('rubric_item_id')->map(fn ($id) => (int) $id))->isNotEmpty()) throw ValidationException::withMessages(['scores' => 'Seluruh komponen rubrik harus diisi.']);
            foreach ($scores as $row) {
                $item = $items->get((int) $row['rubric_item_id']);
                if (! $item || (float) $row['score'] > (float) $item->max_score) throw ValidationException::withMessages(['scores' => 'Skor melebihi nilai maksimum rubrik atau berasal dari sidang lain.']);
                AcademicProjectScore::query()->updateOrCreate(['academic_project_rubric_item_id' => $item->id, 'lecturer_id' => $actor->lecturer->id], ['academic_project_defense_id' => $defense->id, 'score' => $row['score'], 'notes' => $row['notes'] ?? null]);
            }
            return $defense->scores()->where('lecturer_id', $actor->lecturer->id)->get();
        }, 3);
    }

    public function completeDefense(AcademicProject $project, AcademicProjectDefense $defense, array $data, User $actor): AcademicProjectDefense
    {
        return DB::transaction(function () use ($project, $defense, $data, $actor): AcademicProjectDefense {
            $project = $this->lockActiveProject($project)->load('lecturerAssignments');
            $defense = $project->defenses()->with(['rubricItems', 'scores'])->lockForUpdate()->findOrFail($defense->id);
            if ($defense->status !== 'scheduled') throw ValidationException::withMessages(['defense' => 'Seminar/sidang ini sudah ditutup.']);
            if ($defense->rubricItems->isEmpty() || abs((float) $defense->rubricItems->sum('weight') - 100) > 0.001) throw ValidationException::withMessages(['rubric' => 'Rubrik harus lengkap dengan total bobot 100%.']);
            $examinerIds = $project->lecturerAssignments->where('role', 'examiner')->pluck('lecturer_id');
            if ($examinerIds->isEmpty()) throw ValidationException::withMessages(['examiners' => 'Belum ada penguji yang ditetapkan.']);
            $expected = $defense->rubricItems->count() * $examinerIds->count();
            $scores = $defense->scores->whereIn('lecturer_id', $examinerIds);
            if ($scores->count() !== $expected) throw ValidationException::withMessages(['scores' => 'Nilai seluruh komponen dari setiap penguji harus lengkap.']);
            $weightedByExaminer = $examinerIds->map(function ($lecturerId) use ($defense, $scores): float {
                return (float) $defense->rubricItems->sum(function (AcademicProjectRubricItem $item) use ($scores, $lecturerId): float {
                    $score = (float) $scores->first(fn (AcademicProjectScore $row) => (int) $row->lecturer_id === (int) $lecturerId && (int) $row->academic_project_rubric_item_id === (int) $item->id)?->score;
                    return ($score / (float) $item->max_score) * (float) $item->weight;
                });
            });
            $defense->update([...$data, 'status' => 'completed', 'final_score' => round((float) $weightedByExaminer->avg(), 2), 'completed_by' => $actor->id, 'completed_at' => now()]);
            return $defense->fresh();
        }, 3);
    }

    public function publishRepository(AcademicProject $project, array $data, User $actor): AcademicProjectRepository
    {
        return DB::transaction(function () use ($project, $data, $actor): AcademicProjectRepository {
            $project = AcademicProject::query()->lockForUpdate()->findOrFail($project->id);
            if ($project->repository()->exists()) return $project->repository;
            if ($project->status !== 'active') throw ValidationException::withMessages(['project' => 'Kegiatan harus berstatus aktif sebelum repository diterbitkan.']);
            $defense = $project->defenses()->whereIn('defense_type', ['defense', 'final_seminar'])->where('status', 'completed')->latest('completed_at')->first();
            if (! $defense || $defense->result !== 'passed') throw ValidationException::withMessages(['defense' => 'Repository hanya dapat diterbitkan setelah seminar akhir/sidang dinyatakan lulus.']);
            $document = $project->documents()->whereIn('document_type', ['final_report', 'repository'])->where('is_current', true)->latest('id')->first();
            if (! $document) throw ValidationException::withMessages(['document' => 'Unggah laporan akhir atau berkas repository terlebih dahulu.']);
            $repository = $project->repository()->create([...$data, 'final_document_id' => $document->id, 'verification_code' => (string) Str::ulid(), 'published_by' => $actor->id, 'published_at' => now()]);
            $project->update(['status' => 'completed']);
            return $repository;
        }, 3);
    }

    private function lockActiveProject(AcademicProject $project): AcademicProject
    {
        $project = AcademicProject::query()->lockForUpdate()->findOrFail($project->id);
        if ($project->status !== 'active') throw ValidationException::withMessages(['project' => 'Kegiatan harus berstatus aktif untuk menjalankan proses ini.']);
        return $project;
    }

    private function assertNoConflict(AcademicProject $project, array $data): void
    {
        $overlap = AcademicProjectDefense::query()->where('status', 'scheduled')->where('scheduled_at', '<', $data['ends_at'])->where('ends_at', '>', $data['scheduled_at']);
        if ($data['room_id'] && (clone $overlap)->where('room_id', $data['room_id'])->exists()) throw ValidationException::withMessages(['room_id' => 'Ruangan sudah digunakan seminar/sidang lain pada waktu tersebut.']);
        $lecturerIds = $project->lecturerAssignments()->pluck('lecturer_id');
        if ($lecturerIds->isNotEmpty() && (clone $overlap)->whereHas('project.lecturerAssignments', fn (Builder $query) => $query->whereIn('lecturer_id', $lecturerIds))->exists()) throw ValidationException::withMessages(['scheduled_at' => 'Salah satu pembimbing atau penguji memiliki seminar/sidang lain yang beririsan.']);
    }
}
