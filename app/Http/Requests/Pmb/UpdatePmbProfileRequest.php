<?php

namespace App\Http\Requests\Pmb;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdatePmbProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'identity_number' => preg_replace('/\D+/', '', (string) $this->input('identity_number')),
            'phone' => trim((string) $this->input('phone')),
            'guardian_phone' => trim((string) $this->input('guardian_phone')),
        ]);
    }

    public function rules(): array
    {
        $applicationId = $this->user()?->pmbApplication?->id;

        return [
            'program_id' => ['required', 'integer', Rule::exists('programs', 'id')->where('is_active', true)],
            'registration_path' => ['required', Rule::in(['Reguler', 'Prestasi', 'Transfer'])],
            'registration_type' => ['required', Rule::in(['Baru', 'Pindahan'])],
            'registration_wave' => ['nullable', 'string', 'max:50'],
            'full_name' => ['required', 'string', 'min:3', 'max:120'],
            'phone' => ['required', 'string', 'min:9', 'max:30'],
            'identity_number' => ['required', 'digits:16', Rule::unique('pmb_applications', 'identity_number')->ignore($applicationId)],
            'birth_place' => ['required', 'string', 'max:100'],
            'birth_date' => ['required', 'date', 'before:today'],
            'gender' => ['required', Rule::in(['L', 'P'])],
            'address' => ['required', 'string', 'max:1000'],
            'previous_school' => ['required', 'string', 'max:255'],
            'graduation_year' => ['required', 'integer', 'min:1980', 'max:'.now()->year],
            'guardian_name' => ['required', 'string', 'max:255'],
            'guardian_phone' => ['required', 'string', 'min:9', 'max:30'],
        ];
    }
}
