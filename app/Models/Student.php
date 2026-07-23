<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Student extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = ['user_id', 'pmb_application_id', 'program_id', 'academic_advisor_id', 'admission_term_id', 'nim', 'cohort_year', 'registration_type', 'gender', 'birth_date', 'phone', 'address', 'status', 'current_semester'];

    protected function casts(): array
    {
        return ['cohort_year' => 'integer', 'current_semester' => 'integer', 'birth_date' => 'date'];
    }

    public function user(): BelongsTo { return $this->belongsTo(User::class)->withTrashed(); }
    public function pmbApplication(): BelongsTo { return $this->belongsTo(PmbApplication::class, 'pmb_application_id'); }
    public function program(): BelongsTo { return $this->belongsTo(Program::class)->withTrashed(); }
    public function academicAdvisor(): BelongsTo { return $this->belongsTo(Lecturer::class, 'academic_advisor_id')->withTrashed(); }
    public function admissionTerm(): BelongsTo { return $this->belongsTo(AcademicTerm::class, 'admission_term_id')->withTrashed(); }
    public function statusHistories(): HasMany { return $this->hasMany(StudentStatusHistory::class)->latest('effective_on')->latest('id'); }
    public function semesterRegistrations(): HasMany { return $this->hasMany(SemesterRegistration::class); }
    public function billingItems(): HasMany { return $this->hasMany(BillingItem::class); }
    public function payments(): HasMany { return $this->hasMany(Payment::class); }
    public function virtualAccounts(): HasMany { return $this->hasMany(PaymentVirtualAccount::class); }
    public function officialDocuments(): HasMany { return $this->hasMany(OfficialDocument::class); }
    public function serviceRequests(): HasMany { return $this->hasMany(StudentServiceRequest::class); }
    public function academicProjects(): HasMany { return $this->hasMany(AcademicProject::class); }
    public function graduationApplications(): HasMany { return $this->hasMany(GraduationApplication::class); }
}
