<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Room extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'building_id',
        'name',
        'code',
        'floor',
        'type',
        'capacity',
        'facilities',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'floor' => 'integer',
            'capacity' => 'integer',
            'facilities' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public function building(): BelongsTo
    {
        return $this->belongsTo(Building::class)->withTrashed();
    }

    public function classGroups(): HasMany
    {
        return $this->hasMany(ClassGroup::class);
    }
}
