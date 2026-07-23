<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class TracerStudyRequest extends FormRequest
{
    public function authorize(): bool { return true; }
    public function rules(): array
    {
        return ['survey_year' => ['required', 'integer', 'min:2020', 'max:'.(now()->year + 1)], 'employment_status' => ['required', Rule::in(['employed', 'entrepreneur', 'studying', 'seeking', 'other'])], 'waiting_months' => ['nullable', 'integer', 'min:0', 'max:240'], 'company_name' => ['nullable', 'string', 'max:180'], 'position' => ['nullable', 'string', 'max:180'], 'salary_range' => ['nullable', 'string', 'max:80'], 'study_relevance' => ['nullable', 'integer', 'min:1', 'max:5'], 'feedback' => ['nullable', 'string', 'max:5000']];
    }
}
