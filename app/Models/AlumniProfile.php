<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class AlumniProfile extends Model
{
    protected $fillable = ['student_id', 'graduation_application_id', 'alumni_number', 'personal_email', 'phone', 'address', 'employment_status', 'company_name', 'position', 'industry', 'employment_started_on', 'directory_consent'];
    protected function casts(): array { return ['employment_started_on' => 'date', 'directory_consent' => 'boolean']; }
    public function student(): BelongsTo { return $this->belongsTo(Student::class)->withTrashed(); }
    public function graduationApplication(): BelongsTo { return $this->belongsTo(GraduationApplication::class); }
    public function tracerResponses(): HasMany { return $this->hasMany(TracerStudyResponse::class); }
}
