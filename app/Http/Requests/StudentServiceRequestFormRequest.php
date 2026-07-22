<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StudentServiceRequestFormRequest extends FormRequest
{
    public function authorize(): bool { return true; }
    public function rules(): array
    {
        return [
            'service_request_type_id' => ['required_without:resubmit', 'nullable', 'integer', Rule::exists('service_request_types', 'id')->where(fn ($query) => $query->where('is_active', true)->whereNull('deleted_at'))],
            'subject' => ['required', 'string', 'max:200'], 'purpose' => ['required', 'string', 'min:10', 'max:10000'],
            'additional_information' => ['nullable', 'string', 'max:10000'],
            'attachment' => ['nullable', 'file', 'max:5120', 'mimes:pdf,jpg,jpeg,png'],
            'resubmit' => ['sometimes', 'boolean'],
        ];
    }
}
