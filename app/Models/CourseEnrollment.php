<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class CourseEnrollment extends Model
{
    protected $fillable = ['semester_registration_id', 'class_group_id', 'credits', 'status', 'enrolled_at', 'dropped_at', 'final_score', 'letter_grade', 'grade_status', 'grade_published_at', 'grade_finalized_at'];

    protected function casts(): array
    {
        return ['credits' => 'integer', 'enrolled_at' => 'datetime', 'dropped_at' => 'datetime', 'final_score' => 'decimal:2', 'grade_published_at' => 'datetime', 'grade_finalized_at' => 'datetime'];
    }

    public function registration(): BelongsTo { return $this->belongsTo(SemesterRegistration::class, 'semester_registration_id'); }
    public function classGroup(): BelongsTo { return $this->belongsTo(ClassGroup::class)->withTrashed(); }
    public function gradeScores(): HasMany { return $this->hasMany(StudentGradeScore::class); }
    public function lmsSubmissions(): HasMany { return $this->hasMany(LmsSubmission::class); }
    public function attendanceRecords(): HasMany { return $this->hasMany(AttendanceRecord::class); }
}
