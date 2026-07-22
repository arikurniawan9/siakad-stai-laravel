<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class SemesterRegistration extends Model
{
    protected $fillable = ['student_id', 'academic_term_id', 'academic_registration_period_id', 'max_credits', 'previous_gpa', 'credit_limit_source', 'status', 'dispensation_status', 'dispensation_reason', 'dispensation_notes', 'dispensation_decided_by_user_id', 'dispensation_decided_at', 'review_notes', 'reviewed_by_user_id', 'submitted_at', 'reviewed_at'];

    protected function casts(): array
    {
        return ['max_credits' => 'integer', 'previous_gpa' => 'decimal:2', 'dispensation_decided_at' => 'datetime', 'submitted_at' => 'datetime', 'reviewed_at' => 'datetime'];
    }

    public function student(): BelongsTo { return $this->belongsTo(Student::class)->withTrashed(); }
    public function academicTerm(): BelongsTo { return $this->belongsTo(AcademicTerm::class)->withTrashed(); }
    public function period(): BelongsTo { return $this->belongsTo(AcademicRegistrationPeriod::class, 'academic_registration_period_id'); }
    public function reviewedBy(): BelongsTo { return $this->belongsTo(User::class, 'reviewed_by_user_id')->withTrashed(); }
    public function dispensationDecidedBy(): BelongsTo { return $this->belongsTo(User::class, 'dispensation_decided_by_user_id')->withTrashed(); }
    public function enrollments(): HasMany { return $this->hasMany(CourseEnrollment::class); }
    public function courseChangeRequests(): HasMany { return $this->hasMany(CourseChangeRequest::class); }
}
