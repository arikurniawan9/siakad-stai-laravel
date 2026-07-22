<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

final class PmbFee extends Model
{
    use SoftDeletes;

    protected $fillable = ['academic_term_id', 'program_id', 'name', 'registration_path', 'registration_type', 'wave', 'amount', 'starts_on', 'ends_on', 'due_days', 'is_active', 'notes'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'starts_on' => 'date', 'ends_on' => 'date', 'due_days' => 'integer', 'is_active' => 'boolean'];
    }

    public function academicTerm(): BelongsTo { return $this->belongsTo(AcademicTerm::class); }
    public function program(): BelongsTo { return $this->belongsTo(Program::class); }
    public function invoices(): HasMany { return $this->hasMany(PmbInvoice::class); }
}
