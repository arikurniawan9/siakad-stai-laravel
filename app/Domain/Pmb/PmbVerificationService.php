<?php

namespace App\Domain\Pmb;

use App\Models\PmbApplication;
use App\Models\PmbDocument;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class PmbVerificationService
{
    public function decideDocument(PmbApplication $application, PmbDocument $document, string $status, ?string $notes): PmbDocument
    {
        return DB::transaction(function () use ($application, $document, $status, $notes): PmbDocument {
            $application = PmbApplication::query()->lockForUpdate()->findOrFail($application->id);
            $this->ensureSubmitted($application);
            $document = PmbDocument::query()->where('pmb_application_id', $application->id)->lockForUpdate()->findOrFail($document->id);
            $document->update(['status' => $status, 'notes' => $status === 'rejected' ? trim((string) $notes) : null]);

            return $document->fresh();
        }, 3);
    }

    public function returnForCorrection(PmbApplication $application): PmbApplication
    {
        return DB::transaction(function () use ($application): PmbApplication {
            $application = PmbApplication::query()->lockForUpdate()->findOrFail($application->id);
            $this->ensureSubmitted($application);
            if (! $application->documents()->lockForUpdate()->where('status', 'rejected')->exists()) {
                throw ValidationException::withMessages(['application' => 'Tolak minimal satu dokumen beserta catatan sebelum mengembalikan aplikasi.']);
            }
            $application->update(['status' => 'draft', 'submitted_at' => null]);

            return $application->fresh(['documents']);
        }, 3);
    }

    public function verify(PmbApplication $application): PmbApplication
    {
        return DB::transaction(function () use ($application): PmbApplication {
            $application = PmbApplication::query()->lockForUpdate()->findOrFail($application->id);
            $this->ensureSubmitted($application);
            $documents = $application->documents()->lockForUpdate()->get(['type', 'status']);
            $missing = collect(PmbApplicationWorkflowService::REQUIRED_DOCUMENTS)->diff($documents->pluck('type'));
            if ($missing->isNotEmpty() || $documents->contains(fn (PmbDocument $document): bool => $document->status !== 'verified')) {
                throw ValidationException::withMessages(['application' => 'Seluruh dokumen wajib harus berstatus terverifikasi.']);
            }
            $application->update(['status' => 'verified']);

            return $application->fresh(['documents']);
        }, 3);
    }

    private function ensureSubmitted(PmbApplication $application): void
    {
        if ($application->status !== 'submitted') throw ValidationException::withMessages(['application' => 'Hanya aplikasi berstatus submitted yang dapat diverifikasi.']);
    }
}
