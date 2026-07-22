<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class AttendanceSession extends Model
{
    protected $hidden = ['access_code'];
    protected $fillable = ['class_group_id', 'meeting_number', 'starts_at', 'ends_at', 'topic', 'notes', 'delivery_mode', 'status', 'access_code', 'created_by', 'opened_by', 'opened_at', 'closed_by', 'closed_at'];
    protected function casts(): array { return ['meeting_number' => 'integer', 'starts_at' => 'datetime', 'ends_at' => 'datetime', 'access_code' => 'encrypted', 'opened_at' => 'datetime', 'closed_at' => 'datetime']; }
    public function classGroup(): BelongsTo { return $this->belongsTo(ClassGroup::class); }
    public function records(): HasMany { return $this->hasMany(AttendanceRecord::class); }
    public function creator(): BelongsTo { return $this->belongsTo(User::class, 'created_by')->withTrashed(); }
}
