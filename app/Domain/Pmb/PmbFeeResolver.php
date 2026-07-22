<?php

namespace App\Domain\Pmb;

use App\Models\AcademicTerm;
use App\Models\PmbApplication;
use App\Models\PmbFee;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

final class PmbFeeResolver
{
    public function resolve(PmbApplication $application): PmbFee
    {
        $fee = $this->resolveOrNull($application);
        if (! $fee) throw ValidationException::withMessages(['application' => 'Tarif PMB belum tersedia untuk kombinasi periode, program, jalur, jenis, dan gelombang ini. Hubungi panitia.']);

        return $fee;
    }

    public function resolveOrNull(PmbApplication $application): ?PmbFee
    {
        $termId = AcademicTerm::query()->where('is_active', true)->value('id');
        if (! $termId || ! $application->program_id) return null;
        $today = now()->toDateString();

        return PmbFee::query()
            ->where('academic_term_id', $termId)
            ->where('is_active', true)
            ->where(fn (Builder $query) => $query->whereNull('starts_on')->orWhereDate('starts_on', '<=', $today))
            ->where(fn (Builder $query) => $query->whereNull('ends_on')->orWhereDate('ends_on', '>=', $today))
            ->where(fn (Builder $query) => $query->whereNull('program_id')->orWhere('program_id', $application->program_id))
            ->whereIn('registration_path', ['Semua', $application->registration_path])
            ->whereIn('registration_type', ['Semua', $application->registration_type])
            ->where(function (Builder $query) use ($application): void {
                $query->whereNull('wave')->orWhere('wave', '')->orWhere('wave', 'Semua');
                if (filled($application->registration_wave)) $query->orWhere('wave', $application->registration_wave);
            })
            ->orderByRaw('CASE WHEN program_id = ? THEN 1 ELSE 0 END DESC', [$application->program_id])
            ->orderByRaw('CASE WHEN registration_path = ? THEN 1 ELSE 0 END DESC', [$application->registration_path])
            ->orderByRaw('CASE WHEN registration_type = ? THEN 1 ELSE 0 END DESC', [$application->registration_type])
            ->orderByRaw('CASE WHEN wave = ? THEN 1 ELSE 0 END DESC', [$application->registration_wave ?? ''])
            ->orderByDesc('starts_on')
            ->orderByDesc('id')
            ->first();
    }

    public function ensureNoOverlap(array $data, ?PmbFee $ignore = null): void
    {
        if (! ($data['is_active'] ?? false)) return;
        $query = PmbFee::query()
            ->where('is_active', true)
            ->where('academic_term_id', $data['academic_term_id'])
            ->where('registration_path', $data['registration_path'])
            ->where('registration_type', $data['registration_type'])
            ->when($data['program_id'] ?? null, fn (Builder $query, $id) => $query->where('program_id', $id), fn (Builder $query) => $query->whereNull('program_id'))
            ->when($data['wave'] ?? null, fn (Builder $query, $wave) => $query->where('wave', $wave), fn (Builder $query) => $query->whereNull('wave'))
            ->when($ignore, fn (Builder $query) => $query->whereKeyNot($ignore->id));
        if ($data['ends_on'] ?? null) $query->where(fn (Builder $query) => $query->whereNull('starts_on')->orWhereDate('starts_on', '<=', $data['ends_on']));
        if ($data['starts_on'] ?? null) $query->where(fn (Builder $query) => $query->whereNull('ends_on')->orWhereDate('ends_on', '>=', $data['starts_on']));
        if ($query->exists()) throw ValidationException::withMessages(['fee' => 'Tarif aktif dengan cakupan dan rentang tanggal yang bertumpang tindih sudah tersedia.']);
    }
}
