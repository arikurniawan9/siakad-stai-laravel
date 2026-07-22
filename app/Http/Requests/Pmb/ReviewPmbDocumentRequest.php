<?php

namespace App\Http\Requests\Pmb;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ReviewPmbDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('pmb_verification.update');
    }

    public function rules(): array
    {
        return [
            'status' => ['required', Rule::in(['verified', 'rejected'])],
            'notes' => ['nullable', 'string', 'max:1000', Rule::requiredIf($this->input('status') === 'rejected')],
        ];
    }
}
