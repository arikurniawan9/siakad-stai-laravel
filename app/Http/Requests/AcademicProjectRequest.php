<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class AcademicProjectRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'academic_term_id' => ['required', 'integer', Rule::exists('academic_terms', 'id')->whereNull('deleted_at')],
            'project_type' => ['required', Rule::in(['thesis', 'internship', 'community_service'])],
            'title' => ['required', 'string', 'min:10', 'max:250'],
            'abstract' => ['nullable', 'string', 'max:10000'],
            'organization_name' => ['nullable', 'string', 'max:180'],
            'location' => ['nullable', 'string', 'max:250'],
            'starts_on' => ['nullable', 'date'],
            'ends_on' => ['nullable', 'date', 'after_or_equal:starts_on'],
        ];
    }
}
