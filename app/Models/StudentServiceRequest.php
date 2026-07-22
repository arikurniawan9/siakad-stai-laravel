<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

final class StudentServiceRequest extends Model
{
    protected $fillable = ['request_number', 'student_id', 'service_request_type_id', 'subject', 'purpose', 'details', 'attachment_path', 'attachment_name', 'attachment_mime', 'attachment_size', 'status', 'current_stage', 'revision_number', 'submitted_at', 'due_at', 'completed_at', 'cancelled_at', 'last_action_by'];
    protected function casts(): array { return ['details' => 'array', 'attachment_size' => 'integer', 'revision_number' => 'integer', 'submitted_at' => 'datetime', 'due_at' => 'datetime', 'completed_at' => 'datetime', 'cancelled_at' => 'datetime']; }
    public function student(): BelongsTo { return $this->belongsTo(Student::class)->withTrashed(); }
    public function type(): BelongsTo { return $this->belongsTo(ServiceRequestType::class, 'service_request_type_id')->withTrashed(); }
    public function steps(): HasMany { return $this->hasMany(StudentServiceRequestStep::class)->orderBy('sequence'); }
    public function events(): HasMany { return $this->hasMany(StudentServiceRequestEvent::class)->latest('id'); }
    public function document(): HasOne { return $this->hasOne(StudentServiceDocument::class); }
    public function lastActor(): BelongsTo { return $this->belongsTo(User::class, 'last_action_by')->withTrashed(); }
}
