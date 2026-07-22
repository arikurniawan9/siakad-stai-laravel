<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class PaymentVirtualAccount extends Model
{
    protected $fillable = [
        'student_id', 'pmb_application_id', 'pmb_invoice_id', 'provider', 'va_number', 'external_reference',
        'status', 'expires_at', 'metadata',
    ];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'metadata' => 'array'];
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(PmbApplication::class, 'pmb_application_id');
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(PmbInvoice::class, 'pmb_invoice_id');
    }

    public function student(): BelongsTo { return $this->belongsTo(Student::class)->withTrashed(); }
}
