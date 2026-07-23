<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class OutboundNotification extends Model
{
    protected $fillable = ['user_id', 'channel', 'event_key', 'event_type', 'recipient', 'subject', 'content', 'payload', 'status', 'attempts', 'available_at', 'sent_at', 'provider_message_id', 'last_error'];

    protected function casts(): array
    {
        return ['payload' => 'array', 'attempts' => 'integer', 'available_at' => 'datetime', 'sent_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }
}
