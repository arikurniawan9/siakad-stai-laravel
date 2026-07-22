<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class GradeSheet extends Model
{
    protected $attributes = ['status' => 'draft'];

    protected $fillable = ['class_group_id', 'status', 'notes', 'published_by_user_id', 'finalized_by_user_id', 'published_at', 'finalized_at'];

    protected function casts(): array
    {
        return ['published_at' => 'datetime', 'finalized_at' => 'datetime'];
    }

    public function classGroup(): BelongsTo { return $this->belongsTo(ClassGroup::class)->withTrashed(); }
    public function components(): HasMany { return $this->hasMany(GradeComponent::class)->orderBy('sort_order')->orderBy('id'); }
    public function publishedBy(): BelongsTo { return $this->belongsTo(User::class, 'published_by_user_id')->withTrashed(); }
    public function finalizedBy(): BelongsTo { return $this->belongsTo(User::class, 'finalized_by_user_id')->withTrashed(); }
}
