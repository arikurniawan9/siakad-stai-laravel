<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class AcademicProjectLogbook extends Model
{
    protected $fillable = ['academic_project_id', 'activity_on', 'hours', 'activity', 'progress', 'obstacles', 'next_plan', 'status', 'supervisor_notes', 'reviewed_by', 'reviewed_at', 'created_by'];
    protected function casts(): array { return ['activity_on' => 'date', 'hours' => 'decimal:2', 'reviewed_at' => 'datetime']; }
    public function project(): BelongsTo { return $this->belongsTo(AcademicProject::class, 'academic_project_id'); }
    public function reviewer(): BelongsTo { return $this->belongsTo(User::class, 'reviewed_by')->withTrashed(); }
    public function creator(): BelongsTo { return $this->belongsTo(User::class, 'created_by')->withTrashed(); }
}
