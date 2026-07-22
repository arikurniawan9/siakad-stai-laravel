<?php

namespace App\Http\Requests;

use App\Models\SemesterRegistration;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class DecideRegistrationDispensationRequest extends FormRequest
{
    public function authorize(): bool
    {
        $registration = $this->route('registration');

        return $registration instanceof SemesterRegistration && (bool) $this->user()?->can('decideDispensation', $registration);
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['notes' => trim((string) $this->input('notes'))]);
    }

    public function rules(): array
    {
        return ['decision' => ['required', Rule::in(['approved', 'rejected'])], 'notes' => ['required', 'string', 'min:5', 'max:2000']];
    }
}
