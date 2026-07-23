<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class GraduationPeriod extends Model
{
    protected $fillable = ['academic_term_id', 'code', 'name', 'registration_starts_at', 'registration_ends_at', 'judicium_on', 'ceremony_on', 'quota', 'is_active', 'created_by'];
    protected function casts(): array { return ['registration_starts_at' => 'datetime', 'registration_ends_at' => 'datetime', 'judicium_on' => 'date', 'ceremony_on' => 'date', 'quota' => 'integer', 'is_active' => 'boolean']; }
    public function academicTerm(): BelongsTo { return $this->belongsTo(AcademicTerm::class)->withTrashed(); }
    public function applications(): HasMany { return $this->hasMany(GraduationApplication::class); }
    public function creator(): BelongsTo { return $this->belongsTo(User::class, 'created_by')->withTrashed(); }
}
