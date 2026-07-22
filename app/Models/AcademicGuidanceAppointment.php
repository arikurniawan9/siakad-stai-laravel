<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class AcademicGuidanceAppointment extends Model
{
    protected $fillable = ['student_id', 'lecturer_id', 'created_by', 'starts_at', 'ends_at', 'mode', 'location', 'agenda', 'student_notes', 'lecturer_notes', 'status', 'completed_at', 'reminder_sent_at'];
    protected function casts(): array { return ['starts_at' => 'datetime', 'ends_at' => 'datetime', 'completed_at' => 'datetime', 'reminder_sent_at' => 'datetime']; }
    public function student(): BelongsTo { return $this->belongsTo(Student::class)->withTrashed(); }
    public function lecturer(): BelongsTo { return $this->belongsTo(Lecturer::class)->withTrashed(); }
    public function creator(): BelongsTo { return $this->belongsTo(User::class, 'created_by')->withTrashed(); }
}
