<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

final class AcademicProject extends Model
{
    protected $fillable = [
        'project_number', 'student_id', 'program_id', 'academic_term_id', 'project_type',
        'title', 'abstract', 'organization_name', 'location', 'starts_on', 'ends_on',
        'status', 'eligibility_snapshot', 'review_notes', 'reviewed_by', 'submitted_at',
        'reviewed_at', 'created_by',
    ];

    protected function casts(): array
    {
        return ['starts_on' => 'date', 'ends_on' => 'date', 'eligibility_snapshot' => 'array', 'submitted_at' => 'datetime', 'reviewed_at' => 'datetime'];
    }

    public function student(): BelongsTo { return $this->belongsTo(Student::class)->withTrashed(); }
    public function program(): BelongsTo { return $this->belongsTo(Program::class)->withTrashed(); }
    public function academicTerm(): BelongsTo { return $this->belongsTo(AcademicTerm::class)->withTrashed(); }
    public function reviewer(): BelongsTo { return $this->belongsTo(User::class, 'reviewed_by')->withTrashed(); }
    public function creator(): BelongsTo { return $this->belongsTo(User::class, 'created_by')->withTrashed(); }
    public function documents(): HasMany { return $this->hasMany(AcademicProjectDocument::class); }
    public function lecturerAssignments(): HasMany { return $this->hasMany(AcademicProjectLecturer::class); }
    public function logbooks(): HasMany { return $this->hasMany(AcademicProjectLogbook::class); }
    public function guidanceRecords(): HasMany { return $this->hasMany(AcademicProjectGuidanceRecord::class); }
    public function defenses(): HasMany { return $this->hasMany(AcademicProjectDefense::class); }
    public function repository(): HasOne { return $this->hasOne(AcademicProjectRepository::class); }
}
