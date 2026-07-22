<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class EdomResponse extends Model
{
    protected $fillable = ['edom_questionnaire_id', 'student_id', 'class_group_id', 'average_score', 'suggestion', 'submitted_at'];
    protected function casts(): array { return ['average_score' => 'decimal:2', 'submitted_at' => 'datetime']; }
    public function questionnaire(): BelongsTo { return $this->belongsTo(EdomQuestionnaire::class, 'edom_questionnaire_id'); }
    public function student(): BelongsTo { return $this->belongsTo(Student::class); }
    public function classGroup(): BelongsTo { return $this->belongsTo(ClassGroup::class); }
    public function answers(): HasMany { return $this->hasMany(EdomAnswer::class); }
}
