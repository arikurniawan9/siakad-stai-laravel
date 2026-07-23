<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class AcademicProjectScore extends Model
{
    protected $fillable = ['academic_project_defense_id', 'academic_project_rubric_item_id', 'lecturer_id', 'score', 'notes'];
    protected function casts(): array { return ['score' => 'decimal:2']; }
    public function defense(): BelongsTo { return $this->belongsTo(AcademicProjectDefense::class, 'academic_project_defense_id'); }
    public function rubricItem(): BelongsTo { return $this->belongsTo(AcademicProjectRubricItem::class, 'academic_project_rubric_item_id'); }
    public function lecturer(): BelongsTo { return $this->belongsTo(Lecturer::class)->withTrashed(); }
}
