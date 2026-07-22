<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StudentStatusHistory extends Model
{
    use HasFactory;

    protected $fillable = ['student_id', 'academic_term_id', 'changed_by_user_id', 'from_status', 'to_status', 'effective_on', 'reason'];

    protected function casts(): array { return ['effective_on' => 'date']; }
    public function student(): BelongsTo { return $this->belongsTo(Student::class); }
    public function academicTerm(): BelongsTo { return $this->belongsTo(AcademicTerm::class)->withTrashed(); }
    public function changedBy(): BelongsTo { return $this->belongsTo(User::class, 'changed_by_user_id'); }
}
