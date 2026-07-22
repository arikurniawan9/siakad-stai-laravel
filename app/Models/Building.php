<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Building extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'campus_id',
        'name',
        'code',
        'floor_count',
        'description',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'floor_count' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function campus(): BelongsTo
    {
        return $this->belongsTo(Campus::class)->withTrashed();
    }

    public function rooms(): HasMany
    {
        return $this->hasMany(Room::class);
    }
}
