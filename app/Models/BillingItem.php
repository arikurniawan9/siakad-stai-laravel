<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class BillingItem extends Model
{
    protected $fillable = ['student_id', 'academic_term_id', 'invoice_number', 'description', 'category', 'amount', 'paid_amount', 'due_on', 'status', 'notes', 'created_by', 'waived_by', 'waiver_reason', 'waived_at'];
    protected function casts(): array { return ['amount' => 'decimal:2', 'paid_amount' => 'decimal:2', 'due_on' => 'date', 'waived_at' => 'datetime']; }
    public function student(): BelongsTo { return $this->belongsTo(Student::class)->withTrashed(); }
    public function academicTerm(): BelongsTo { return $this->belongsTo(AcademicTerm::class)->withTrashed(); }
    public function allocations(): HasMany { return $this->hasMany(PaymentAllocation::class); }
    public function creator(): BelongsTo { return $this->belongsTo(User::class, 'created_by')->withTrashed(); }
    public function waivedBy(): BelongsTo { return $this->belongsTo(User::class, 'waived_by')->withTrashed(); }
}
