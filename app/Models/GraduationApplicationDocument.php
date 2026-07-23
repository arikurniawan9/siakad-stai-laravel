<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class GraduationApplicationDocument extends Model
{
    protected $fillable = ['graduation_application_id', 'document_type', 'version', 'disk', 'path', 'original_name', 'mime_type', 'size', 'sha256', 'is_current', 'uploaded_by'];
    protected function casts(): array { return ['version' => 'integer', 'size' => 'integer', 'is_current' => 'boolean']; }
    public function application(): BelongsTo { return $this->belongsTo(GraduationApplication::class, 'graduation_application_id'); }
    public function uploader(): BelongsTo { return $this->belongsTo(User::class, 'uploaded_by')->withTrashed(); }
}
