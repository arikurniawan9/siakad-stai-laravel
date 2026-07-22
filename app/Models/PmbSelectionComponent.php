<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class PmbSelectionComponent extends Model
{
    protected $fillable = ['pmb_selection_id', 'name', 'weight', 'max_score', 'sort_order'];

    protected function casts(): array
    {
        return ['weight' => 'decimal:2', 'max_score' => 'decimal:2', 'sort_order' => 'integer'];
    }

    public function selection(): BelongsTo { return $this->belongsTo(PmbSelection::class, 'pmb_selection_id'); }
    public function scores(): HasMany { return $this->hasMany(PmbSelectionScore::class); }
}
