<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class LmsAssignment extends Model
{
    protected $fillable = ['class_group_id', 'created_by', 'title', 'instructions', 'due_at', 'max_points', 'external_url', 'attachment_path', 'attachment_name', 'attachment_mime', 'attachment_size', 'is_published', 'published_at'];

    protected function casts(): array { return ['due_at' => 'datetime', 'max_points' => 'decimal:2', 'attachment_size' => 'integer', 'is_published' => 'boolean', 'published_at' => 'datetime']; }
    public function classGroup(): BelongsTo { return $this->belongsTo(ClassGroup::class); }
    public function creator(): BelongsTo { return $this->belongsTo(User::class, 'created_by')->withTrashed(); }
    public function submissions(): HasMany { return $this->hasMany(LmsSubmission::class); }
}
