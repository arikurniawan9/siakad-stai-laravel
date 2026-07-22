<?php

namespace App\Http\Requests;

use App\Models\SemesterRegistration;
use Illuminate\Foundation\Http\FormRequest;

final class RequestRegistrationDispensationRequest extends FormRequest
{
    public function authorize(): bool
    {
        $registration = $this->route('registration');

        return $registration instanceof SemesterRegistration && (bool) $this->user()?->can('update', $registration);
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['reason' => trim((string) $this->input('reason'))]);
    }

    public function rules(): array
    {
        return ['reason' => ['required', 'string', 'min:10', 'max:2000']];
    }
}
