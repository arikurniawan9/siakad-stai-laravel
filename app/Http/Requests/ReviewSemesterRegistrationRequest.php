<?php

namespace App\Http\Requests;

use App\Models\SemesterRegistration;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ReviewSemesterRegistrationRequest extends FormRequest
{
    public function authorize(): bool
    {
        $registration = $this->route('registration');

        return $registration instanceof SemesterRegistration && (bool) $this->user()?->can('review', $registration);
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['notes' => trim((string) $this->input('notes'))]);
    }

    public function rules(): array
    {
        return [
            'decision' => ['required', Rule::in(['approved', 'rejected'])],
            'notes' => ['nullable', 'required_if:decision,rejected', 'string', 'max:2000'],
            'max_credits' => ['nullable', 'integer', 'min:1', 'max:30'],
        ];
    }
}
