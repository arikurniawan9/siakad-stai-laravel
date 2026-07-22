<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class StudentEarlyWarning extends Model
{
    protected $fillable = ['student_id', 'assigned_lecturer_id', 'warning_type', 'severity', 'score', 'evidence', 'status', 'resolution_notes', 'detected_at', 'resolved_at'];
    protected function casts(): array { return ['score' => 'decimal:2', 'evidence' => 'array', 'detected_at' => 'datetime', 'resolved_at' => 'datetime']; }
    public function student(): BelongsTo { return $this->belongsTo(Student::class)->withTrashed(); }
    public function assignedLecturer(): BelongsTo { return $this->belongsTo(Lecturer::class, 'assigned_lecturer_id')->withTrashed(); }
}
