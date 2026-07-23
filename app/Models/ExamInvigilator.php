<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class ExamInvigilator extends Model
{
    protected $fillable = ['exam_schedule_id', 'lecturer_id', 'role', 'assigned_by'];

    public function examSchedule(): BelongsTo { return $this->belongsTo(ExamSchedule::class); }
    public function lecturer(): BelongsTo { return $this->belongsTo(Lecturer::class)->withTrashed(); }
    public function assignedBy(): BelongsTo { return $this->belongsTo(User::class, 'assigned_by')->withTrashed(); }
}
