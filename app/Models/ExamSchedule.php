<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

final class ExamSchedule extends Model
{
    protected $fillable = ['academic_term_id', 'class_group_id', 'exam_type', 'exam_date', 'starts_at', 'ends_at', 'room_id', 'delivery_mode', 'status', 'verification_code', 'notes', 'created_by', 'updated_by'];

    protected function casts(): array
    {
        return ['exam_date' => 'date'];
    }

    public function academicTerm(): BelongsTo { return $this->belongsTo(AcademicTerm::class)->withTrashed(); }
    public function classGroup(): BelongsTo { return $this->belongsTo(ClassGroup::class)->withTrashed(); }
    public function room(): BelongsTo { return $this->belongsTo(Room::class)->withTrashed(); }
    public function creator(): BelongsTo { return $this->belongsTo(User::class, 'created_by')->withTrashed(); }
    public function updater(): BelongsTo { return $this->belongsTo(User::class, 'updated_by')->withTrashed(); }
    public function invigilators(): HasMany { return $this->hasMany(ExamInvigilator::class); }
    public function participants(): HasMany { return $this->hasMany(ExamParticipant::class); }
    public function report(): HasOne { return $this->hasOne(ExamReport::class); }
}
