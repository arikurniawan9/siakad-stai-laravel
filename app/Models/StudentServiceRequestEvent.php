<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class StudentServiceRequestEvent extends Model
{
    public $timestamps = false;
    protected $fillable = ['student_service_request_id', 'actor_id', 'action', 'from_status', 'to_status', 'stage', 'notes', 'metadata', 'created_at'];
    protected function casts(): array { return ['metadata' => 'array', 'created_at' => 'datetime']; }
    public function request(): BelongsTo { return $this->belongsTo(StudentServiceRequest::class, 'student_service_request_id'); }
    public function actor(): BelongsTo { return $this->belongsTo(User::class, 'actor_id')->withTrashed(); }
}
