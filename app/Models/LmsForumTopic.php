<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class LmsForumTopic extends Model
{
    protected $fillable = ['class_group_id', 'user_id', 'title', 'content', 'is_pinned', 'is_locked'];
    protected function casts(): array { return ['is_pinned' => 'boolean', 'is_locked' => 'boolean']; }
    public function classGroup(): BelongsTo { return $this->belongsTo(ClassGroup::class); }
    public function user(): BelongsTo { return $this->belongsTo(User::class)->withTrashed(); }
    public function comments(): HasMany { return $this->hasMany(LmsForumComment::class); }
}
