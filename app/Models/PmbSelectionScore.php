<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class PmbSelectionScore extends Model
{
    protected $fillable = ['pmb_selection_result_id', 'pmb_selection_component_id', 'score'];

    protected function casts(): array
    {
        return ['score' => 'decimal:2'];
    }

    public function result(): BelongsTo { return $this->belongsTo(PmbSelectionResult::class, 'pmb_selection_result_id'); }
    public function component(): BelongsTo { return $this->belongsTo(PmbSelectionComponent::class, 'pmb_selection_component_id'); }
}
