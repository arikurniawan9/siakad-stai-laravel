<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class EdomQuestion extends Model
{
    protected $fillable = ['edom_questionnaire_id', 'category', 'question', 'type', 'sort_order', 'is_required'];
    protected function casts(): array { return ['sort_order' => 'integer', 'is_required' => 'boolean']; }
    public function questionnaire(): BelongsTo { return $this->belongsTo(EdomQuestionnaire::class, 'edom_questionnaire_id'); }
    public function answers(): HasMany { return $this->hasMany(EdomAnswer::class); }
}
