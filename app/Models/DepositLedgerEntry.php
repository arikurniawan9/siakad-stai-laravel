<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class DepositLedgerEntry extends Model
{
    protected $fillable = ['student_id', 'payment_id', 'amount', 'balance_after', 'entry_type', 'description'];
    protected function casts(): array { return ['amount' => 'decimal:2', 'balance_after' => 'decimal:2']; }
    public function student(): BelongsTo { return $this->belongsTo(Student::class)->withTrashed(); }
    public function payment(): BelongsTo { return $this->belongsTo(Payment::class); }
}
