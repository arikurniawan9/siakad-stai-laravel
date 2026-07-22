<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AcademicTerm extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = ['name', 'code', 'semester', 'starts_on', 'ends_on', 'is_active'];

    protected function casts(): array
    {
        return ['starts_on' => 'date', 'ends_on' => 'date', 'is_active' => 'boolean'];
    }

    public function classGroups(): HasMany
    {
        return $this->hasMany(ClassGroup::class);
    }

    public function registrationPeriods(): HasMany
    {
        return $this->hasMany(AcademicRegistrationPeriod::class);
    }
}
