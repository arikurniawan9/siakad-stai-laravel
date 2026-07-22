<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class PaymentAllocation extends Model
{
    protected $fillable = ['payment_id', 'billing_item_id', 'amount'];
    protected function casts(): array { return ['amount' => 'decimal:2']; }
    public function payment(): BelongsTo { return $this->belongsTo(Payment::class); }
    public function billingItem(): BelongsTo { return $this->belongsTo(BillingItem::class); }
}
