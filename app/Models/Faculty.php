<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Faculty extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = ['campus_id', 'name', 'code'];

    public function campus(): BelongsTo
    {
        return $this->belongsTo(Campus::class);
    }

    public function programs(): HasMany
    {
        return $this->hasMany(Program::class);
    }
}
