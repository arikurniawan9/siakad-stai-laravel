<?php

namespace App\Domain\Pmb;

use App\Models\PmbApplication;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class PmbApplicationWorkflowService
{
    public function __construct(private readonly PmbInvoiceService $invoices) {}

    public const REQUIRED_DOCUMENTS = ['photo', 'identity_card', 'diploma', 'transcript'];

    private const REQUIRED_PROFILE = [
        'program_id', 'registration_path', 'registration_type', 'full_name', 'phone', 'identity_number', 'birth_place',
        'birth_date', 'gender', 'address', 'previous_school', 'graduation_year', 'guardian_name', 'guardian_phone',
    ];

    public function updateProfile(PmbApplication $application, array $data, User $actor): PmbApplication
    {
        return DB::transaction(function () use ($application, $data, $actor): PmbApplication {
            $application = PmbApplication::query()->lockForUpdate()->findOrFail($application->id);
            $this->ensureDraftOwner($application, $actor);
            $application->update([...$data, 'profile_completed_at' => now()]);
            $actor->forceFill(['name' => $data['full_name']])->save();

            return $application->fresh(['program', 'documents']);
        }, 3);
    }

    public function submit(PmbApplication $application, User $actor): PmbApplication
    {
        return DB::transaction(function () use ($application, $actor): PmbApplication {
            $application = PmbApplication::query()->lockForUpdate()->findOrFail($application->id);
            $this->ensureDraftOwner($application, $actor);

            $missingProfile = collect(self::REQUIRED_PROFILE)->filter(fn (string $field): bool => blank($application->{$field}))->values();
            if ($missingProfile->isNotEmpty()) {
                throw ValidationException::withMessages(['application' => 'Lengkapi seluruh biodata sebelum mengirim aplikasi.']);
            }
            $documentTypes = $application->documents()->lockForUpdate()->pluck('type');
            $missingDocuments = collect(self::REQUIRED_DOCUMENTS)->diff($documentTypes);
            if ($missingDocuments->isNotEmpty()) {
                throw ValidationException::withMessages(['application' => 'Unggah seluruh dokumen wajib sebelum mengirim aplikasi.']);
            }
            if ($application->documents()->where('status', 'rejected')->exists()) {
                throw ValidationException::withMessages(['application' => 'Ganti seluruh dokumen yang ditolak sebelum mengirim ulang aplikasi.']);
            }

            $application->update(['status' => 'submitted', 'submitted_at' => now()]);
            $this->invoices->issue($application);

            return $application->fresh(['program', 'documents', 'invoice']);
        }, 3);
    }

    private function ensureDraftOwner(PmbApplication $application, User $actor): void
    {
        if ($application->user_id !== $actor->id || $application->status !== 'draft') {
            throw ValidationException::withMessages(['application' => 'Aplikasi yang sudah dikirim tidak dapat diubah.']);
        }
    }
}
