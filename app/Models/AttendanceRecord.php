<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class AttendanceRecord extends Model
{
    protected $fillable = ['attendance_session_id', 'course_enrollment_id', 'status', 'checked_in_at', 'notes', 'recorded_by'];
    protected function casts(): array { return ['checked_in_at' => 'datetime']; }
    public function session(): BelongsTo { return $this->belongsTo(AttendanceSession::class, 'attendance_session_id'); }
    public function enrollment(): BelongsTo { return $this->belongsTo(CourseEnrollment::class, 'course_enrollment_id'); }
    public function recorder(): BelongsTo { return $this->belongsTo(User::class, 'recorded_by')->withTrashed(); }
}
