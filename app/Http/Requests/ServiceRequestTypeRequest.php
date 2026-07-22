<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ServiceRequestTypeRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        $id = $this->route('type')?->id;
        return [
            'code' => ['required', 'string', 'max:40', 'regex:/^[A-Z0-9_-]+$/', Rule::unique('service_request_types', 'code')->ignore($id)],
            'name' => ['required', 'string', 'max:150'], 'category' => ['required', Rule::in(['academic', 'finance', 'general'])],
            'description' => ['nullable', 'string', 'max:5000'], 'workflow' => ['required', 'array', 'min:1', 'max:4'],
            'workflow.*' => ['required', 'distinct', Rule::in(['advisor', 'program', 'finance', 'academic'])],
            'requirements_text' => ['nullable', 'string', 'max:5000'], 'template_subject' => ['required', 'string', 'max:200'],
            'template_body' => ['required', 'string', 'max:10000'], 'sla_business_days' => ['required', 'integer', 'between:1,30'],
            'requires_attachment' => ['required', 'boolean'], 'requires_financial_clearance' => ['required', 'boolean'], 'is_active' => ['required', 'boolean'],
        ];
    }
}
