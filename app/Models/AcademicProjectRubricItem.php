<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class AcademicProjectRubricItem extends Model
{
    protected $fillable = ['academic_project_defense_id', 'name', 'weight', 'max_score', 'sort_order'];
    protected function casts(): array { return ['weight' => 'decimal:2', 'max_score' => 'decimal:2', 'sort_order' => 'integer']; }
    public function defense(): BelongsTo { return $this->belongsTo(AcademicProjectDefense::class, 'academic_project_defense_id'); }
    public function scores(): HasMany { return $this->hasMany(AcademicProjectScore::class); }
}
