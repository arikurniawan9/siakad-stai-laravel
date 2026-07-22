<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class GradeComponent extends Model
{
    protected $fillable = ['grade_sheet_id', 'name', 'weight', 'max_score', 'sort_order'];

    protected function casts(): array
    {
        return ['weight' => 'decimal:2', 'max_score' => 'decimal:2', 'sort_order' => 'integer'];
    }

    public function gradeSheet(): BelongsTo { return $this->belongsTo(GradeSheet::class); }
    public function scores(): HasMany { return $this->hasMany(StudentGradeScore::class); }
}
