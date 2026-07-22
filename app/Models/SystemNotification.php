<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class SystemNotification extends Model
{
    protected $fillable = ['user_id', 'type', 'title', 'message', 'link', 'data', 'read_at'];
    protected function casts(): array { return ['data' => 'array', 'read_at' => 'datetime']; }
    public function user(): BelongsTo { return $this->belongsTo(User::class)->withTrashed(); }
}
