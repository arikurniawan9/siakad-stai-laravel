<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class ClassGroup extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = ['academic_term_id', 'course_id', 'lecturer_id', 'room_id', 'name', 'capacity', 'enrolled_count', 'room', 'day', 'starts_at', 'ends_at', 'is_active'];

    protected function casts(): array
    {
        return ['capacity' => 'integer', 'enrolled_count' => 'integer', 'is_active' => 'boolean'];
    }

    public function academicTerm(): BelongsTo
    {
        return $this->belongsTo(AcademicTerm::class)->withTrashed();
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class)->withTrashed();
    }

    public function lecturer(): BelongsTo
    {
        return $this->belongsTo(Lecturer::class)->withTrashed();
    }

    public function assignedRoom(): BelongsTo
    {
        return $this->belongsTo(Room::class, 'room_id')->withTrashed();
    }

    public function enrollments(): HasMany
    {
        return $this->hasMany(CourseEnrollment::class);
    }

    public function gradeSheet(): HasOne
    {
        return $this->hasOne(GradeSheet::class);
    }

    public function materials(): HasMany { return $this->hasMany(LmsMaterial::class); }
    public function assignments(): HasMany { return $this->hasMany(LmsAssignment::class); }
    public function forumTopics(): HasMany { return $this->hasMany(LmsForumTopic::class); }
    public function edomResponses(): HasMany { return $this->hasMany(EdomResponse::class); }
    public function attendanceSessions(): HasMany { return $this->hasMany(AttendanceSession::class); }
}
