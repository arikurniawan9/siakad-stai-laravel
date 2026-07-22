<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class StudentServiceRequestStep extends Model
{
    protected $fillable = ['student_service_request_id', 'sequence', 'stage', 'status', 'decided_by', 'decided_at', 'decision_notes'];
    protected function casts(): array { return ['sequence' => 'integer', 'decided_at' => 'datetime']; }
    public function request(): BelongsTo { return $this->belongsTo(StudentServiceRequest::class, 'student_service_request_id'); }
    public function decider(): BelongsTo { return $this->belongsTo(User::class, 'decided_by')->withTrashed(); }
}
