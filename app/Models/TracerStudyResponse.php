<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class TracerStudyResponse extends Model
{
    protected $fillable = ['alumni_profile_id', 'survey_year', 'employment_status', 'waiting_months', 'company_name', 'position', 'salary_range', 'study_relevance', 'feedback', 'submitted_at'];
    protected function casts(): array { return ['survey_year' => 'integer', 'waiting_months' => 'integer', 'study_relevance' => 'integer', 'submitted_at' => 'datetime']; }
    public function alumniProfile(): BelongsTo { return $this->belongsTo(AlumniProfile::class); }
}
