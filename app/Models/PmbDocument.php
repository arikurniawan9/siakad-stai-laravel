<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class PmbDocument extends Model
{
    protected $fillable = ['pmb_application_id', 'type', 'disk', 'path', 'original_name', 'mime_type', 'size', 'status', 'notes', 'uploaded_at'];

    protected function casts(): array
    {
        return ['uploaded_at' => 'datetime', 'size' => 'integer'];
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(PmbApplication::class, 'pmb_application_id');
    }
}
