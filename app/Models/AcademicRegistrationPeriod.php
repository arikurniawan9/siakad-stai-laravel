<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class AcademicRegistrationPeriod extends Model
{
    protected $fillable = ['academic_term_id', 'starts_at', 'ends_at', 'changes_starts_at', 'changes_ends_at', 'default_max_credits', 'is_open', 'is_changes_open'];

    protected function casts(): array
    {
        return ['starts_at' => 'datetime', 'ends_at' => 'datetime', 'changes_starts_at' => 'datetime', 'changes_ends_at' => 'datetime', 'default_max_credits' => 'integer', 'is_open' => 'boolean', 'is_changes_open' => 'boolean'];
    }

    public function academicTerm(): BelongsTo { return $this->belongsTo(AcademicTerm::class)->withTrashed(); }
    public function registrations(): HasMany { return $this->hasMany(SemesterRegistration::class); }
}
