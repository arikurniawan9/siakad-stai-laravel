<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Campus extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = ['uuid', 'name', 'code', 'address', 'is_active'];

    protected static function booted(): void
    {
        static::creating(function (self $campus): void {
            $campus->uuid ??= (string) Str::uuid();
        });
    }

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function faculties(): HasMany
    {
        return $this->hasMany(Faculty::class);
    }

    public function buildings(): HasMany
    {
        return $this->hasMany(Building::class);
    }
}
