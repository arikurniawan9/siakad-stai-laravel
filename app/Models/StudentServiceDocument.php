<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class StudentServiceDocument extends Model
{
    protected $fillable = ['student_service_request_id', 'document_number', 'verification_code', 'content_hash', 'snapshot', 'issued_by', 'issued_at', 'revoked_by', 'revoked_at', 'revocation_reason'];
    protected function casts(): array { return ['snapshot' => 'array', 'issued_at' => 'datetime', 'revoked_at' => 'datetime']; }
    public function request(): BelongsTo { return $this->belongsTo(StudentServiceRequest::class, 'student_service_request_id'); }
    public function issuer(): BelongsTo { return $this->belongsTo(User::class, 'issued_by')->withTrashed(); }
    public function revoker(): BelongsTo { return $this->belongsTo(User::class, 'revoked_by')->withTrashed(); }
}
