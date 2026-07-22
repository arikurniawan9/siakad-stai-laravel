<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class AcademicGuidanceNote extends Model
{
    protected $fillable = ['student_id', 'lecturer_id', 'appointment_id', 'created_by', 'title', 'content', 'follow_up_due', 'follow_up_status'];
    protected function casts(): array { return ['follow_up_due' => 'date']; }
    public function student(): BelongsTo { return $this->belongsTo(Student::class)->withTrashed(); }
    public function lecturer(): BelongsTo { return $this->belongsTo(Lecturer::class)->withTrashed(); }
    public function appointment(): BelongsTo { return $this->belongsTo(AcademicGuidanceAppointment::class); }
}
