<?php

namespace App\Domain\Graduation;

use App\Models\AlumniProfile;
use App\Models\TracerStudyResponse;
use Illuminate\Support\Facades\DB;

final class AlumniService
{
    public function updateProfile(AlumniProfile $profile, array $data): AlumniProfile
    {
        return DB::transaction(function () use ($profile, $data): AlumniProfile { $profile = AlumniProfile::query()->lockForUpdate()->findOrFail($profile->id); $profile->update($data); return $profile->fresh(); }, 3);
    }
    public function submitTracer(AlumniProfile $profile, array $data): TracerStudyResponse
    {
        return DB::transaction(function () use ($profile, $data): TracerStudyResponse { AlumniProfile::query()->lockForUpdate()->findOrFail($profile->id); return $profile->tracerResponses()->updateOrCreate(['survey_year' => $data['survey_year']], [...$data, 'submitted_at' => now()]); }, 3);
    }
}
