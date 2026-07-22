<?php

namespace App\Domain\Academic;

use App\Models\ClassGroup;
use App\Models\CourseEnrollment;
use App\Models\GradeComponent;
use App\Models\GradeSheet;
use App\Models\StudentGradeScore;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class GradeSheetService
{
    public function addComponent(ClassGroup $classGroup, array $data): GradeComponent
    {
        return DB::transaction(function () use ($classGroup, $data): GradeComponent {
            $sheet = $this->lockedDraftSheet($classGroup, true);
            $this->ensureWeight($sheet, (float) $data['weight']);

            return $sheet->components()->create($data);
        }, 3);
    }

    public function updateComponent(ClassGroup $classGroup, GradeComponent $component, array $data): GradeComponent
    {
        return DB::transaction(function () use ($classGroup, $component, $data): GradeComponent {
            $sheet = $this->lockedDraftSheet($classGroup);
            $component = $sheet->components()->lockForUpdate()->findOrFail($component->id);
            $this->ensureWeight($sheet, (float) $data['weight'], $component->id);
            $tooHigh = $component->scores()->where('score', '>', (float) $data['max_score'])->exists();
            if ($tooHigh) throw ValidationException::withMessages(['max_score' => 'Nilai maksimum tidak boleh lebih kecil dari skor mahasiswa yang sudah tersimpan.']);
            $component->update($data);

            return $component->fresh();
        }, 3);
    }

    public function removeComponent(ClassGroup $classGroup, GradeComponent $component): void
    {
        DB::transaction(function () use ($classGroup, $component): void {
            $sheet = $this->lockedDraftSheet($classGroup);
            $sheet->components()->lockForUpdate()->findOrFail($component->id)->delete();
        }, 3);
    }

    public function saveScores(ClassGroup $classGroup, CourseEnrollment $enrollment, array $scores, User $actor): void
    {
        DB::transaction(function () use ($classGroup, $enrollment, $scores, $actor): void {
            $sheet = $this->lockedDraftSheet($classGroup);
            $enrollment = CourseEnrollment::query()->lockForUpdate()->findOrFail($enrollment->id);
            if ($enrollment->class_group_id !== $classGroup->id || $enrollment->status !== 'enrolled') {
                throw ValidationException::withMessages(['scores' => 'Mahasiswa tidak terdaftar aktif pada kelas ini.']);
            }
            $components = $sheet->components()->lockForUpdate()->get();
            if ($components->isEmpty()) throw ValidationException::withMessages(['scores' => 'Tambahkan komponen nilai terlebih dahulu.']);
            $normalized = collect($scores)->mapWithKeys(fn ($score, $id): array => [(int) $id => $score]);
            if ($components->pluck('id')->diff($normalized->keys())->isNotEmpty() || $normalized->keys()->diff($components->pluck('id'))->isNotEmpty()) {
                throw ValidationException::withMessages(['scores' => 'Seluruh komponen nilai harus diisi.']);
            }
            foreach ($components as $component) {
                $score = (float) $normalized->get($component->id);
                if ($score < 0 || $score > (float) $component->max_score) {
                    throw ValidationException::withMessages(["scores.{$component->id}" => "Skor {$component->name} harus antara 0 dan {$component->max_score}."]);
                }
                StudentGradeScore::query()->updateOrCreate(
                    ['course_enrollment_id' => $enrollment->id, 'grade_component_id' => $component->id],
                    ['score' => $score, 'updated_by_user_id' => $actor->id]
                );
            }
        }, 3);
    }

    public function publish(ClassGroup $classGroup, User $actor, ?string $notes = null): GradeSheet
    {
        return DB::transaction(function () use ($classGroup, $actor, $notes): GradeSheet {
            $sheet = $this->lockedDraftSheet($classGroup);
            $components = $sheet->components()->lockForUpdate()->get();
            if ($components->isEmpty() || abs((float) $components->sum('weight') - 100) > 0.001) {
                throw ValidationException::withMessages(['grade_sheet' => 'Total bobot komponen harus tepat 100% sebelum nilai dipublikasikan.']);
            }
            $enrollments = CourseEnrollment::query()->where('class_group_id', $classGroup->id)->where('status', 'enrolled')->orderBy('id')->lockForUpdate()->get();
            if ($enrollments->isEmpty()) throw ValidationException::withMessages(['grade_sheet' => 'Kelas belum memiliki mahasiswa aktif.']);
            $allScores = StudentGradeScore::query()->whereIn('course_enrollment_id', $enrollments->pluck('id'))->whereIn('grade_component_id', $components->pluck('id'))->lockForUpdate()->get()->groupBy('course_enrollment_id');

            foreach ($enrollments as $enrollment) {
                $scores = $allScores->get($enrollment->id, collect())->keyBy('grade_component_id');
                if ($components->pluck('id')->diff($scores->keys())->isNotEmpty()) {
                    throw ValidationException::withMessages(['grade_sheet' => 'Nilai seluruh komponen untuk setiap mahasiswa harus lengkap.']);
                }
                $finalScore = $this->calculate($components, $scores);
                $enrollment->update(['final_score' => $finalScore, 'letter_grade' => $this->letterFor($finalScore), 'grade_status' => 'published', 'grade_published_at' => now(), 'grade_finalized_at' => null]);
            }
            $sheet->update(['status' => 'published', 'notes' => filled($notes) ? trim((string) $notes) : null, 'published_by_user_id' => $actor->id, 'published_at' => now(), 'finalized_by_user_id' => null, 'finalized_at' => null]);

            return $sheet->fresh();
        }, 3);
    }

    public function finalize(ClassGroup $classGroup, User $actor): GradeSheet
    {
        return DB::transaction(function () use ($classGroup, $actor): GradeSheet {
            $sheet = GradeSheet::query()->where('class_group_id', $classGroup->id)->lockForUpdate()->firstOrFail();
            if ($sheet->status !== 'published') throw ValidationException::withMessages(['grade_sheet' => 'Hanya lembar nilai published yang dapat difinalisasi.']);
            CourseEnrollment::query()->where('class_group_id', $classGroup->id)->where('status', 'enrolled')->lockForUpdate()->get()->each->update(['grade_status' => 'finalized', 'grade_finalized_at' => now()]);
            $sheet->update(['status' => 'finalized', 'finalized_by_user_id' => $actor->id, 'finalized_at' => now()]);

            return $sheet->fresh();
        }, 3);
    }

    public function reopen(ClassGroup $classGroup): GradeSheet
    {
        return DB::transaction(function () use ($classGroup): GradeSheet {
            $sheet = GradeSheet::query()->where('class_group_id', $classGroup->id)->lockForUpdate()->firstOrFail();
            if ($sheet->status !== 'published') throw ValidationException::withMessages(['grade_sheet' => 'Hanya nilai published yang belum final dapat dibuka kembali.']);
            CourseEnrollment::query()->where('class_group_id', $classGroup->id)->where('status', 'enrolled')->lockForUpdate()->get()->each->update(['grade_status' => 'draft', 'grade_published_at' => null]);
            $sheet->update(['status' => 'draft', 'published_by_user_id' => null, 'published_at' => null]);

            return $sheet->fresh();
        }, 3);
    }

    public function letterFor(float $score): string
    {
        foreach (config('siakad.grade_scale', []) as $scale) {
            if ($score >= (float) $scale['minimum']) return (string) $scale['letter'];
        }

        return 'E';
    }

    public function pointsFor(?string $letter): float
    {
        foreach (config('siakad.grade_scale', []) as $scale) {
            if ($scale['letter'] === $letter) return (float) $scale['points'];
        }

        return 0;
    }

    private function lockedDraftSheet(ClassGroup $classGroup, bool $create = false): GradeSheet
    {
        ClassGroup::query()->lockForUpdate()->findOrFail($classGroup->id);
        $sheet = GradeSheet::query()->where('class_group_id', $classGroup->id)->lockForUpdate()->first();
        if (! $sheet && $create) $sheet = GradeSheet::create(['class_group_id' => $classGroup->id]);
        if (! $sheet) throw ValidationException::withMessages(['grade_sheet' => 'Lembar nilai belum tersedia. Tambahkan komponen terlebih dahulu.']);
        if ($sheet->status !== 'draft') throw ValidationException::withMessages(['grade_sheet' => 'Lembar nilai yang sudah dipublikasikan tidak dapat diubah.']);

        return $sheet;
    }

    private function ensureWeight(GradeSheet $sheet, float $weight, ?int $except = null): void
    {
        $total = (float) $sheet->components()->when($except, fn ($query) => $query->whereKeyNot($except))->sum('weight') + $weight;
        if ($total > 100.001) throw ValidationException::withMessages(['weight' => 'Total bobot komponen tidak boleh melebihi 100%.']);
    }

    private function calculate(Collection $components, Collection $scores): float
    {
        return round((float) $components->sum(function (GradeComponent $component) use ($scores): float {
            return ((float) $scores->get($component->id)->score / (float) $component->max_score) * (float) $component->weight;
        }), 2);
    }
}
