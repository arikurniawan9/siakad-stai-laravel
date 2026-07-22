<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class PmbSelectionResult extends Model
{
    protected $fillable = ['pmb_selection_id', 'pmb_application_id', 'student_id', 'final_score', 'decision', 'finalized_at'];

    protected function casts(): array
    {
        return ['final_score' => 'decimal:2', 'finalized_at' => 'datetime'];
    }

    public function selection(): BelongsTo { return $this->belongsTo(PmbSelection::class, 'pmb_selection_id'); }
    public function application(): BelongsTo { return $this->belongsTo(PmbApplication::class, 'pmb_application_id'); }
    public function student(): BelongsTo { return $this->belongsTo(Student::class)->withTrashed(); }
    public function scores(): HasMany { return $this->hasMany(PmbSelectionScore::class, 'pmb_selection_result_id'); }
}
