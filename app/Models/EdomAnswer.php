<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class EdomAnswer extends Model
{
    protected $fillable = ['edom_response_id', 'edom_question_id', 'rating', 'essay_answer'];
    protected function casts(): array { return ['rating' => 'integer']; }
    public function response(): BelongsTo { return $this->belongsTo(EdomResponse::class, 'edom_response_id'); }
    public function question(): BelongsTo { return $this->belongsTo(EdomQuestion::class, 'edom_question_id'); }
}
