<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class AcademicProjectRepository extends Model
{
    protected $fillable = ['academic_project_id', 'final_document_id', 'title', 'abstract', 'keywords', 'publication_consent', 'verification_code', 'published_by', 'published_at'];
    protected function casts(): array { return ['keywords' => 'array', 'publication_consent' => 'boolean', 'published_at' => 'datetime']; }
    public function project(): BelongsTo { return $this->belongsTo(AcademicProject::class, 'academic_project_id'); }
    public function finalDocument(): BelongsTo { return $this->belongsTo(AcademicProjectDocument::class, 'final_document_id'); }
    public function publisher(): BelongsTo { return $this->belongsTo(User::class, 'published_by')->withTrashed(); }
}
