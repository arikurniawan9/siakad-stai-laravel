<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class StudentGradeScore extends Model
{
    protected $fillable = ['course_enrollment_id', 'grade_component_id', 'score', 'updated_by_user_id'];

    protected function casts(): array
    {
        return ['score' => 'decimal:2'];
    }

    public function enrollment(): BelongsTo { return $this->belongsTo(CourseEnrollment::class, 'course_enrollment_id'); }
    public function component(): BelongsTo { return $this->belongsTo(GradeComponent::class, 'grade_component_id'); }
    public function updatedBy(): BelongsTo { return $this->belongsTo(User::class, 'updated_by_user_id')->withTrashed(); }
}
