<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class AcademicProjectLecturer extends Model
{
    protected $fillable = ['academic_project_id', 'lecturer_id', 'role', 'sequence', 'assigned_by', 'assigned_at'];

    protected function casts(): array { return ['sequence' => 'integer', 'assigned_at' => 'datetime']; }

    public function project(): BelongsTo { return $this->belongsTo(AcademicProject::class, 'academic_project_id'); }
    public function lecturer(): BelongsTo { return $this->belongsTo(Lecturer::class)->withTrashed(); }
    public function assigner(): BelongsTo { return $this->belongsTo(User::class, 'assigned_by')->withTrashed(); }
}
