<?php

namespace App\Domain\Pmb;

use App\Models\AcademicTerm;
use App\Models\PmbApplication;
use App\Models\PmbSelection;
use App\Models\PmbSelectionComponent;
use App\Models\PmbSelectionResult;
use App\Models\PmbSelectionScore;
use App\Models\Program;
use App\Models\Student;
use App\Models\StudentStatusHistory;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;
use Spatie\Permission\Models\Role;

final class PmbSelectionService
{
    public function addComponent(PmbSelection $selection, array $data): PmbSelectionComponent
    {
        return DB::transaction(function () use ($selection, $data): PmbSelectionComponent {
            $selection = $this->lockedDraft($selection);
            if ($selection->components()->where('name', $data['name'])->exists()) {
                throw ValidationException::withMessages(['name' => 'Nama komponen sudah digunakan pada seleksi ini.']);
            }

            $totalWeight = (float) $selection->components()->lockForUpdate()->sum('weight') + (float) $data['weight'];
            if ($totalWeight > 100.0001) {
                throw ValidationException::withMessages(['weight' => 'Total bobot komponen tidak boleh melebihi 100%.']);
            }

            return $selection->components()->create([...$data, 'sort_order' => $data['sort_order'] ?? 0]);
        }, 3);
    }

    public function removeComponent(PmbSelection $selection, PmbSelectionComponent $component): void
    {
        DB::transaction(function () use ($selection, $component): void {
            $selection = $this->lockedDraft($selection);
            $component = $selection->components()->lockForUpdate()->findOrFail($component->id);
            $component->delete();
        }, 3);
    }

    public function assignCandidate(PmbSelection $selection, PmbApplication $application): PmbSelectionResult
    {
        return DB::transaction(function () use ($selection, $application): PmbSelectionResult {
            $selection = $this->lockedDraft($selection);
            $application = PmbApplication::query()->with(['fee', 'invoice', 'user', 'program'])->lockForUpdate()->findOrFail($application->id);
            $invoice = $application->invoice()->lockForUpdate()->first();
            $fee = $application->fee()->lockForUpdate()->first();

            if ($application->status !== 'verified') {
                throw ValidationException::withMessages(['pmb_application_id' => 'Hanya aplikasi terverifikasi yang dapat mengikuti seleksi.']);
            }
            if (! $invoice || ! in_array($invoice->status, ['paid', 'waived'], true)) {
                throw ValidationException::withMessages(['pmb_application_id' => 'Invoice pendaftaran harus lunas sebelum calon mengikuti seleksi.']);
            }
            if (! $fee || $fee->academic_term_id !== $selection->academic_term_id) {
                throw ValidationException::withMessages(['pmb_application_id' => 'Periode tarif aplikasi tidak sesuai dengan periode seleksi.']);
            }
            if ($selection->program_id && $application->program_id !== $selection->program_id) {
                throw ValidationException::withMessages(['pmb_application_id' => 'Program studi aplikasi tidak sesuai dengan cakupan seleksi.']);
            }
            if (! $application->user || ! $application->user->is_active || ! $application->program || ! $application->program->is_active) {
                throw ValidationException::withMessages(['pmb_application_id' => 'Akun dan program studi calon harus aktif.']);
            }
            if (PmbSelectionResult::query()->where('pmb_application_id', $application->id)->lockForUpdate()->exists()) {
                throw ValidationException::withMessages(['pmb_application_id' => 'Calon sudah terdaftar pada jadwal seleksi.']);
            }

            return $selection->results()->create(['pmb_application_id' => $application->id]);
        }, 3);
    }

    public function removeCandidate(PmbSelection $selection, PmbSelectionResult $result): void
    {
        DB::transaction(function () use ($selection, $result): void {
            $selection = $this->lockedDraft($selection);
            $selection->results()->lockForUpdate()->findOrFail($result->id)->delete();
        }, 3);
    }

    public function saveScores(PmbSelection $selection, PmbSelectionResult $result, array $scores): PmbSelectionResult
    {
        return DB::transaction(function () use ($selection, $result, $scores): PmbSelectionResult {
            $selection = $this->lockedDraft($selection);
            $result = $selection->results()->lockForUpdate()->findOrFail($result->id);
            $components = $selection->components()->lockForUpdate()->get();
            if ($components->isEmpty()) {
                throw ValidationException::withMessages(['scores' => 'Tambahkan komponen seleksi sebelum menginput nilai.']);
            }

            $submittedIds = collect(array_keys($scores))->map(fn ($id): int => (int) $id)->sort()->values();
            if ($submittedIds->all() !== $components->pluck('id')->map(fn ($id): int => (int) $id)->sort()->values()->all()) {
                throw ValidationException::withMessages(['scores' => 'Nilai wajib diisi untuk seluruh komponen seleksi.']);
            }

            foreach ($components as $component) {
                $score = (float) $scores[$component->id];
                if ($score < 0 || $score > (float) $component->max_score) {
                    throw ValidationException::withMessages(["scores.{$component->id}" => "Nilai {$component->name} harus antara 0 dan {$component->max_score}."]);
                }
                PmbSelectionScore::query()->updateOrCreate(
                    ['pmb_selection_result_id' => $result->id, 'pmb_selection_component_id' => $component->id],
                    ['score' => $score]
                );
            }

            return $result->fresh(['scores']);
        }, 3);
    }

    public function finalize(PmbSelection $selection, User $actor): PmbSelection
    {
        return DB::transaction(function () use ($selection, $actor): PmbSelection {
            $selection = PmbSelection::query()->lockForUpdate()->findOrFail($selection->id);
            if ($selection->status === 'finalized') return $selection;

            $components = $selection->components()->lockForUpdate()->get();
            $results = $selection->results()->lockForUpdate()->get();
            if ($components->isEmpty() || $results->isEmpty()) {
                throw ValidationException::withMessages(['selection' => 'Seleksi membutuhkan minimal satu komponen dan satu peserta.']);
            }
            if (abs((float) $components->sum('weight') - 100) > 0.001) {
                throw ValidationException::withMessages(['selection' => 'Total bobot komponen harus tepat 100% sebelum finalisasi.']);
            }

            foreach ($results as $result) {
                $scores = PmbSelectionScore::query()->where('pmb_selection_result_id', $result->id)->lockForUpdate()->get()->keyBy('pmb_selection_component_id');
                if ($scores->count() !== $components->count()) {
                    throw ValidationException::withMessages(['selection' => 'Nilai seluruh peserta dan komponen harus lengkap sebelum finalisasi.']);
                }

                $application = PmbApplication::query()->lockForUpdate()->findOrFail($result->pmb_application_id);
                if ($application->status !== 'verified') {
                    throw ValidationException::withMessages(['selection' => "Aplikasi {$application->registration_number} tidak lagi berstatus terverifikasi."]);
                }

                $finalScore = round($components->sum(function (PmbSelectionComponent $component) use ($scores): float {
                    $score = (float) $scores->get($component->id)->score;

                    return ($score / (float) $component->max_score) * (float) $component->weight;
                }), 2);
                $decision = $finalScore >= (float) $selection->passing_grade ? 'accepted' : 'rejected';
                $result->update(['final_score' => $finalScore, 'decision' => $decision, 'finalized_at' => now()]);
                $application->update(['status' => $decision]);
            }

            $selection->update(['status' => 'finalized', 'finalized_at' => now(), 'finalized_by_user_id' => $actor->id]);

            return $selection->fresh(['components', 'results.application']);
        }, 3);
    }

    public function convert(PmbSelection $selection, PmbSelectionResult $result, User $actor): Student
    {
        return DB::transaction(function () use ($selection, $result, $actor): Student {
            $selection = PmbSelection::query()->lockForUpdate()->findOrFail($selection->id);
            $result = PmbSelectionResult::query()->where('pmb_selection_id', $selection->id)->lockForUpdate()->findOrFail($result->id);
            $application = PmbApplication::query()->lockForUpdate()->findOrFail($result->pmb_application_id);

            $existing = Student::withTrashed()->where('pmb_application_id', $application->id)->lockForUpdate()->first();
            if ($existing) return $existing;

            if ($selection->status !== 'finalized' || $result->decision !== 'accepted' || $application->status !== 'accepted') {
                throw ValidationException::withMessages(['conversion' => 'Hanya peserta dengan hasil seleksi lulus yang dapat dikonversi.']);
            }
            if (! $application->user_id || ! $application->program_id) {
                throw ValidationException::withMessages(['conversion' => 'Akun dan program studi aplikasi wajib tersedia.']);
            }

            $user = User::query()->lockForUpdate()->findOrFail($application->user_id);
            $program = Program::query()->where('is_active', true)->lockForUpdate()->find($application->program_id);
            $term = AcademicTerm::query()->lockForUpdate()->findOrFail($selection->academic_term_id);
            if (! $user->is_active || ! $program) {
                throw ValidationException::withMessages(['conversion' => 'Akun dan program studi harus aktif sebelum konversi.']);
            }
            if (Student::withTrashed()->where('user_id', $user->id)->lockForUpdate()->exists()) {
                throw ValidationException::withMessages(['conversion' => 'Akun calon sudah terhubung dengan mahasiswa lain.']);
            }

            $cohortYear = (int) ($term->starts_on?->year ?? $selection->starts_at->year);
            $nim = $this->nextNim($program, $cohortYear);
            $registrationType = $application->registration_type === 'Pindahan'
                ? 'Pindahan'
                : ($application->registration_path === 'Transfer' ? 'Transfer' : 'Reguler');

            $student = Student::create([
                'user_id' => $user->id,
                'pmb_application_id' => $application->id,
                'program_id' => $program->id,
                'admission_term_id' => $term->id,
                'nim' => $nim,
                'cohort_year' => $cohortYear,
                'registration_type' => $registrationType,
                'gender' => $application->gender,
                'birth_date' => $application->birth_date,
                'phone' => $application->phone,
                'address' => $application->address,
                'status' => 'Aktif',
                'current_semester' => 1,
            ]);
            StudentStatusHistory::create([
                'student_id' => $student->id,
                'academic_term_id' => $term->id,
                'changed_by_user_id' => $actor->id,
                'from_status' => null,
                'to_status' => 'Aktif',
                'effective_on' => now()->toDateString(),
                'reason' => "Konversi kelulusan PMB {$application->registration_number}",
            ]);
            $result->update(['student_id' => $student->id]);
            $user->assignRole(Role::findOrCreate('Mahasiswa', 'web'));
            $user->forceFill(['active_role' => 'Mahasiswa'])->save();

            return $student->fresh(['program', 'admissionTerm']);
        }, 3);
    }

    private function lockedDraft(PmbSelection $selection): PmbSelection
    {
        $selection = PmbSelection::query()->lockForUpdate()->findOrFail($selection->id);
        if ($selection->status !== 'draft') {
            throw ValidationException::withMessages(['selection' => 'Seleksi yang sudah difinalisasi tidak dapat diubah.']);
        }

        return $selection;
    }

    private function nextNim(Program $program, int $cohortYear): string
    {
        DB::table('nim_sequences')->insertOrIgnore([
            'program_id' => $program->id,
            'cohort_year' => $cohortYear,
            'last_number' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $sequence = DB::table('nim_sequences')->where(['program_id' => $program->id, 'cohort_year' => $cohortYear])->lockForUpdate()->first();
        $number = (int) $sequence->last_number;
        $prefix = preg_replace('/[^A-Z0-9]/', '', strtoupper($program->code)) ?: 'MHS';
        $format = (string) config('siakad.nim.format', '{PROGRAM}{YEAR}{SEQUENCE}');
        $digits = max(1, min(10, (int) config('siakad.nim.sequence_digits', 4)));
        if (! str_contains($format, '{SEQUENCE}')) {
            throw new LogicException('SIAKAD_NIM_FORMAT wajib memuat placeholder {SEQUENCE}.');
        }

        do {
            $number++;
            $nim = strtr($format, [
                '{PROGRAM}' => $prefix,
                '{YEAR}' => (string) $cohortYear,
                '{SEQUENCE}' => str_pad((string) $number, $digits, '0', STR_PAD_LEFT),
            ]);
            if (strlen($nim) > 30) throw new LogicException('Hasil SIAKAD_NIM_FORMAT tidak boleh melebihi 30 karakter.');
        } while (Student::withTrashed()->where('nim', $nim)->exists());

        DB::table('nim_sequences')->where('id', $sequence->id)->update(['last_number' => $number, 'updated_at' => now()]);

        return $nim;
    }
}
