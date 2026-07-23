<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class AcademicProjectDocument extends Model
{
    protected $fillable = ['academic_project_id', 'document_type', 'version', 'disk', 'path', 'original_name', 'mime_type', 'size', 'sha256', 'is_current', 'uploaded_by'];

    protected function casts(): array { return ['version' => 'integer', 'size' => 'integer', 'is_current' => 'boolean']; }

    public function project(): BelongsTo { return $this->belongsTo(AcademicProject::class, 'academic_project_id'); }
    public function uploader(): BelongsTo { return $this->belongsTo(User::class, 'uploaded_by')->withTrashed(); }
}
