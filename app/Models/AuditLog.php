<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class AuditLog extends Model
{
    protected $guarded = [];
    protected function casts(): array { return ['old_data' => 'array', 'new_data' => 'array']; }
    public function user(): BelongsTo { return $this->belongsTo(User::class)->withTrashed(); }
}
