<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class OfficialDocument extends Model
{
    protected $fillable = ['document_number', 'verification_code', 'type', 'student_id', 'source_type', 'source_id', 'content_hash', 'snapshot', 'issued_by', 'issued_at', 'revoked_by', 'revoked_at', 'revocation_reason'];

    protected function casts(): array
    {
        return ['snapshot' => 'array', 'issued_at' => 'datetime', 'revoked_at' => 'datetime'];
    }

    public function student(): BelongsTo { return $this->belongsTo(Student::class)->withTrashed(); }
    public function issuer(): BelongsTo { return $this->belongsTo(User::class, 'issued_by')->withTrashed(); }
    public function revoker(): BelongsTo { return $this->belongsTo(User::class, 'revoked_by')->withTrashed(); }
}
