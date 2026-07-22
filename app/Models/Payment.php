<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class Payment extends Model
{
    protected $fillable = ['student_id', 'bank_webhook_event_id', 'provider', 'external_reference', 'amount', 'currency', 'paid_at', 'status', 'recorded_by', 'notes'];
    protected function casts(): array { return ['amount' => 'decimal:2', 'paid_at' => 'datetime']; }
    public function student(): BelongsTo { return $this->belongsTo(Student::class)->withTrashed(); }
    public function allocations(): HasMany { return $this->hasMany(PaymentAllocation::class); }
    public function recorder(): BelongsTo { return $this->belongsTo(User::class, 'recorded_by')->withTrashed(); }
}
