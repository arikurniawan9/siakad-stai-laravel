<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
final class GuidanceAvailabilitySlot extends Model { protected $fillable = ['lecturer_id', 'weekday', 'starts_at', 'ends_at', 'mode', 'is_active']; protected function casts(): array { return ['weekday' => 'integer', 'is_active' => 'boolean']; } public function lecturer(): BelongsTo { return $this->belongsTo(Lecturer::class)->withTrashed(); } }
