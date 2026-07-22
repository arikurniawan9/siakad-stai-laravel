<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

final class PmbInvoice extends Model
{
    protected $fillable = ['pmb_application_id', 'pmb_fee_id', 'invoice_number', 'description', 'amount', 'paid_amount', 'due_at', 'status', 'issued_at'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'paid_amount' => 'decimal:2', 'due_at' => 'datetime', 'issued_at' => 'datetime'];
    }

    public function application(): BelongsTo { return $this->belongsTo(PmbApplication::class, 'pmb_application_id'); }
    public function fee(): BelongsTo { return $this->belongsTo(PmbFee::class, 'pmb_fee_id')->withTrashed(); }
    public function virtualAccount(): HasOne { return $this->hasOne(PaymentVirtualAccount::class, 'pmb_invoice_id'); }
}
