<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Course extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = ['program_id', 'code', 'name', 'credits', 'type', 'is_active'];

    protected function casts(): array
    {
        return ['credits' => 'integer', 'is_active' => 'boolean'];
    }

    public function program(): BelongsTo
    {
        return $this->belongsTo(Program::class);
    }

    public function classGroups(): HasMany
    {
        return $this->hasMany(ClassGroup::class);
    }

    public function curriculumCourses(): HasMany
    {
        return $this->hasMany(CurriculumCourse::class);
    }

    public function prerequisites(): HasMany
    {
        return $this->hasMany(CoursePrerequisite::class);
    }

    public function requiredBy(): HasMany
    {
        return $this->hasMany(CoursePrerequisite::class, 'prerequisite_course_id');
    }
}
