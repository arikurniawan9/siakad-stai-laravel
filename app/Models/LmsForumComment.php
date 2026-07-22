<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class LmsForumComment extends Model
{
    protected $fillable = ['lms_forum_topic_id', 'user_id', 'content'];
    public function topic(): BelongsTo { return $this->belongsTo(LmsForumTopic::class, 'lms_forum_topic_id'); }
    public function user(): BelongsTo { return $this->belongsTo(User::class)->withTrashed(); }
}
