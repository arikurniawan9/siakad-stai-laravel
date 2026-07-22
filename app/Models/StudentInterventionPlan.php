<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
final class StudentInterventionPlan extends Model { protected $fillable = ['student_id', 'warning_id', 'assigned_lecturer_id', 'created_by', 'title', 'action_plan', 'due_on', 'status', 'outcome', 'reminder_sent_at']; protected function casts(): array { return ['due_on' => 'date', 'reminder_sent_at' => 'datetime']; } public function student(): BelongsTo { return $this->belongsTo(Student::class)->withTrashed(); } public function warning(): BelongsTo { return $this->belongsTo(StudentEarlyWarning::class); } public function assignedLecturer(): BelongsTo { return $this->belongsTo(Lecturer::class, 'assigned_lecturer_id')->withTrashed(); } }
