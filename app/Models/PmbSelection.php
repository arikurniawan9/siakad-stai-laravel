<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class PmbSelection extends Model
{
    protected $fillable = ['academic_term_id', 'program_id', 'name', 'starts_at', 'ends_at', 'passing_grade', 'status', 'finalized_at', 'finalized_by_user_id'];

    protected function casts(): array
    {
        return ['starts_at' => 'datetime', 'ends_at' => 'datetime', 'passing_grade' => 'decimal:2', 'finalized_at' => 'datetime'];
    }

    public function academicTerm(): BelongsTo { return $this->belongsTo(AcademicTerm::class)->withTrashed(); }
    public function program(): BelongsTo { return $this->belongsTo(Program::class)->withTrashed(); }
    public function finalizedBy(): BelongsTo { return $this->belongsTo(User::class, 'finalized_by_user_id')->withTrashed(); }
    public function components(): HasMany { return $this->hasMany(PmbSelectionComponent::class)->orderBy('sort_order')->orderBy('id'); }
    public function results(): HasMany { return $this->hasMany(PmbSelectionResult::class); }
}
