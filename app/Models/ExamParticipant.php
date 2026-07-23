<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class ExamParticipant extends Model
{
    protected $fillable = [
        'exam_schedule_id', 'course_enrollment_id', 'student_id', 'participant_number',
        'student_nim', 'student_name', 'is_eligible', 'eligibility_snapshot',
        'attendance_status', 'notes', 'recorded_by', 'recorded_at',
    ];

    protected function casts(): array
    {
        return ['is_eligible' => 'boolean', 'eligibility_snapshot' => 'array', 'recorded_at' => 'datetime'];
    }

    public function examSchedule(): BelongsTo { return $this->belongsTo(ExamSchedule::class); }
    public function enrollment(): BelongsTo { return $this->belongsTo(CourseEnrollment::class, 'course_enrollment_id'); }
    public function student(): BelongsTo { return $this->belongsTo(Student::class)->withTrashed(); }
    public function recordedBy(): BelongsTo { return $this->belongsTo(User::class, 'recorded_by')->withTrashed(); }
}
