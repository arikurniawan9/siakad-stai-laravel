<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

final class GraduationApplication extends Model
{
    protected $fillable = ['application_number', 'graduation_period_id', 'student_id', 'status', 'eligibility_snapshot', 'review_notes', 'reviewed_by', 'submitted_at', 'reviewed_at', 'graduated_at'];
    protected function casts(): array { return ['eligibility_snapshot' => 'array', 'submitted_at' => 'datetime', 'reviewed_at' => 'datetime', 'graduated_at' => 'datetime']; }
    public function period(): BelongsTo { return $this->belongsTo(GraduationPeriod::class, 'graduation_period_id'); }
    public function student(): BelongsTo { return $this->belongsTo(Student::class)->withTrashed(); }
    public function reviewer(): BelongsTo { return $this->belongsTo(User::class, 'reviewed_by')->withTrashed(); }
    public function documents(): HasMany { return $this->hasMany(GraduationApplicationDocument::class); }
    public function graduateDocuments(): HasMany { return $this->hasMany(GraduateDocument::class); }
    public function alumniProfile(): HasOne { return $this->hasOne(AlumniProfile::class); }
}
