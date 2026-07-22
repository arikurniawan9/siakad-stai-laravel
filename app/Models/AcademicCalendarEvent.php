<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class AcademicCalendarEvent extends Model
{
    protected $fillable = ['academic_term_id', 'title', 'event_type', 'starts_at', 'ends_at', 'description', 'location', 'is_published', 'created_by', 'updated_by'];

    protected function casts(): array
    {
        return ['starts_at' => 'datetime', 'ends_at' => 'datetime', 'is_published' => 'boolean'];
    }

    public function academicTerm(): BelongsTo { return $this->belongsTo(AcademicTerm::class)->withTrashed(); }
    public function creator(): BelongsTo { return $this->belongsTo(User::class, 'created_by')->withTrashed(); }
    public function updater(): BelongsTo { return $this->belongsTo(User::class, 'updated_by')->withTrashed(); }
}
