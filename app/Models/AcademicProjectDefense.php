<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class AcademicProjectDefense extends Model
{
    protected $fillable = ['academic_project_id', 'defense_type', 'scheduled_at', 'ends_at', 'room_id', 'delivery_mode', 'status', 'verification_code', 'minutes_summary', 'incidents', 'result', 'final_score', 'created_by', 'completed_by', 'completed_at'];
    protected function casts(): array { return ['scheduled_at' => 'datetime', 'ends_at' => 'datetime', 'final_score' => 'decimal:2', 'completed_at' => 'datetime']; }
    public function project(): BelongsTo { return $this->belongsTo(AcademicProject::class, 'academic_project_id'); }
    public function room(): BelongsTo { return $this->belongsTo(Room::class)->withTrashed(); }
    public function rubricItems(): HasMany { return $this->hasMany(AcademicProjectRubricItem::class); }
    public function scores(): HasMany { return $this->hasMany(AcademicProjectScore::class); }
    public function creator(): BelongsTo { return $this->belongsTo(User::class, 'created_by')->withTrashed(); }
    public function completer(): BelongsTo { return $this->belongsTo(User::class, 'completed_by')->withTrashed(); }
}
