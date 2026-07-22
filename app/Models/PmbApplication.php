<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class PmbApplication extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id', 'program_id', 'pmb_fee_id', 'registration_path', 'registration_type', 'registration_wave', 'registration_number', 'full_name', 'email', 'phone',
        'identity_number', 'birth_place', 'birth_date', 'gender', 'address', 'previous_school', 'graduation_year',
        'guardian_name', 'guardian_phone', 'status', 'submitted_at', 'profile_completed_at',
    ];

    protected function casts(): array
    {
        return ['birth_date' => 'date', 'submitted_at' => 'datetime', 'profile_completed_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    public function program(): BelongsTo
    {
        return $this->belongsTo(Program::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(PmbDocument::class);
    }

    public function fee(): BelongsTo
    {
        return $this->belongsTo(PmbFee::class, 'pmb_fee_id')->withTrashed();
    }

    public function invoice(): HasOne
    {
        return $this->hasOne(PmbInvoice::class, 'pmb_application_id');
    }

    public function virtualAccount(): HasOne
    {
        return $this->hasOne(PaymentVirtualAccount::class, 'pmb_application_id');
    }

    public function selectionResult(): HasOne
    {
        return $this->hasOne(PmbSelectionResult::class, 'pmb_application_id');
    }

    public function student(): HasOne
    {
        return $this->hasOne(Student::class, 'pmb_application_id');
    }
}
