<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CurriculumCourse extends Model
{
    use HasFactory;

    protected $fillable = ['curriculum_id', 'course_id', 'semester', 'credits', 'is_required'];

    protected function casts(): array
    {
        return [
            'semester' => 'integer',
            'credits' => 'integer',
            'is_required' => 'boolean',
        ];
    }

    public function curriculum(): BelongsTo
    {
        return $this->belongsTo(Curriculum::class);
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class)->withTrashed();
    }
}
