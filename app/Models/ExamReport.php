<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class ExamReport extends Model
{
    protected $fillable = [
        'exam_schedule_id', 'status', 'actual_starts_at', 'actual_ends_at',
        'material_summary', 'incidents', 'notes', 'participant_count', 'present_count',
        'absent_count', 'sick_count', 'excused_count', 'verification_code',
        'prepared_by', 'finalized_by', 'finalized_at',
    ];

    protected function casts(): array
    {
        return ['actual_starts_at' => 'datetime', 'actual_ends_at' => 'datetime', 'finalized_at' => 'datetime'];
    }

    public function examSchedule(): BelongsTo { return $this->belongsTo(ExamSchedule::class); }
    public function preparedBy(): BelongsTo { return $this->belongsTo(User::class, 'prepared_by')->withTrashed(); }
    public function finalizedBy(): BelongsTo { return $this->belongsTo(User::class, 'finalized_by')->withTrashed(); }
}
