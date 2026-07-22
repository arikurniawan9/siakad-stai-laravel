<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

final class EdomQuestionnaire extends Model
{
    use SoftDeletes;
    protected $fillable = ['academic_term_id', 'title', 'description', 'starts_at', 'ends_at', 'is_active'];
    protected function casts(): array { return ['starts_at' => 'datetime', 'ends_at' => 'datetime', 'is_active' => 'boolean']; }
    public function academicTerm(): BelongsTo { return $this->belongsTo(AcademicTerm::class)->withTrashed(); }
    public function questions(): HasMany { return $this->hasMany(EdomQuestion::class)->orderBy('sort_order')->orderBy('id'); }
    public function responses(): HasMany { return $this->hasMany(EdomResponse::class); }
    public function isOpen(): bool { return $this->is_active && now()->between($this->starts_at, $this->ends_at); }
}
