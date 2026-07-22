<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class CourseChangeRequest extends Model
{
    protected $fillable = ['semester_registration_id', 'type', 'class_group_id', 'course_enrollment_id', 'reason', 'status', 'review_notes', 'reviewed_by_user_id', 'reviewed_at', 'cancelled_at'];
    protected $attributes = ['status' => 'requested'];

    protected function casts(): array { return ['reviewed_at' => 'datetime', 'cancelled_at' => 'datetime']; }
    public function registration(): BelongsTo { return $this->belongsTo(SemesterRegistration::class, 'semester_registration_id'); }
    public function classGroup(): BelongsTo { return $this->belongsTo(ClassGroup::class)->withTrashed(); }
    public function enrollment(): BelongsTo { return $this->belongsTo(CourseEnrollment::class, 'course_enrollment_id'); }
    public function reviewedBy(): BelongsTo { return $this->belongsTo(User::class, 'reviewed_by_user_id')->withTrashed(); }
}
