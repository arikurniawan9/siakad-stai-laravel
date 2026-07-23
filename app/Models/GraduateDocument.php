<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class GraduateDocument extends Model
{
    protected $fillable = ['graduation_application_id', 'document_type', 'document_number', 'verification_code', 'snapshot', 'content_hash', 'issued_by', 'issued_at', 'revoked_by', 'revoked_at', 'revocation_reason'];
    protected function casts(): array { return ['snapshot' => 'array', 'issued_at' => 'datetime', 'revoked_at' => 'datetime']; }
    public function application(): BelongsTo { return $this->belongsTo(GraduationApplication::class, 'graduation_application_id'); }
    public function issuer(): BelongsTo { return $this->belongsTo(User::class, 'issued_by')->withTrashed(); }
}
