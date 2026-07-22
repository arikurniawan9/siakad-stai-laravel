<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class LmsSubmission extends Model
{
    protected $fillable = ['lms_assignment_id', 'course_enrollment_id', 'answer_text', 'external_url', 'attachment_path', 'attachment_name', 'attachment_mime', 'attachment_size', 'status', 'submitted_at', 'score', 'feedback', 'graded_by', 'graded_at'];

    protected function casts(): array { return ['attachment_size' => 'integer', 'submitted_at' => 'datetime', 'score' => 'decimal:2', 'graded_at' => 'datetime']; }
    public function assignment(): BelongsTo { return $this->belongsTo(LmsAssignment::class, 'lms_assignment_id'); }
    public function enrollment(): BelongsTo { return $this->belongsTo(CourseEnrollment::class, 'course_enrollment_id'); }
    public function grader(): BelongsTo { return $this->belongsTo(User::class, 'graded_by')->withTrashed(); }
}
