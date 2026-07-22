<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

final class ServiceRequestType extends Model
{
    use SoftDeletes;

    protected $fillable = ['code', 'name', 'category', 'description', 'workflow', 'requirements_text', 'template_subject', 'template_body', 'sla_business_days', 'requires_attachment', 'requires_financial_clearance', 'is_active', 'created_by'];
    protected function casts(): array { return ['workflow' => 'array', 'sla_business_days' => 'integer', 'requires_attachment' => 'boolean', 'requires_financial_clearance' => 'boolean', 'is_active' => 'boolean']; }
    public function requests(): HasMany { return $this->hasMany(StudentServiceRequest::class); }
    public function creator(): BelongsTo { return $this->belongsTo(User::class, 'created_by')->withTrashed(); }
}
