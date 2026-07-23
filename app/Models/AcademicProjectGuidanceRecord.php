<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class AcademicProjectGuidanceRecord extends Model
{
    protected $fillable = ['academic_project_id', 'lecturer_id', 'occurred_at', 'mode', 'discussion', 'feedback', 'follow_up', 'created_by'];
    protected function casts(): array { return ['occurred_at' => 'datetime']; }
    public function project(): BelongsTo { return $this->belongsTo(AcademicProject::class, 'academic_project_id'); }
    public function lecturer(): BelongsTo { return $this->belongsTo(Lecturer::class)->withTrashed(); }
    public function creator(): BelongsTo { return $this->belongsTo(User::class, 'created_by')->withTrashed(); }
}
