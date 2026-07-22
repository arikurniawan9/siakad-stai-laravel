<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Lecturer extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'user_id',
        'program_id',
        'name',
        'nidn',
        'employee_number',
        'academic_title',
        'employment_status',
        'expertise',
        'is_active',
    ];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    public function program(): BelongsTo
    {
        return $this->belongsTo(Program::class)->withTrashed();
    }

    public function classGroups(): HasMany
    {
        return $this->hasMany(ClassGroup::class);
    }

    public function advisedStudents(): HasMany
    {
        return $this->hasMany(Student::class, 'academic_advisor_id');
    }
}
