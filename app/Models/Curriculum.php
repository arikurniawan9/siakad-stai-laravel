<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Curriculum extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'program_id',
        'effective_term_id',
        'name',
        'code',
        'target_credits',
        'is_active',
        'description',
    ];

    protected function casts(): array
    {
        return [
            'target_credits' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function program(): BelongsTo
    {
        return $this->belongsTo(Program::class)->withTrashed();
    }

    public function effectiveTerm(): BelongsTo
    {
        return $this->belongsTo(AcademicTerm::class, 'effective_term_id')->withTrashed();
    }

    public function curriculumCourses(): HasMany
    {
        return $this->hasMany(CurriculumCourse::class);
    }
}
