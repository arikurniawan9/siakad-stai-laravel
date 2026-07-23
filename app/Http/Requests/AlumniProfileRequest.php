<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class AlumniProfileRequest extends FormRequest
{
    public function authorize(): bool { return true; }
    public function rules(): array
    {
        return ['personal_email' => ['nullable', 'email:rfc', 'max:255'], 'phone' => ['nullable', 'string', 'max:40'], 'address' => ['nullable', 'string', 'max:2000'], 'employment_status' => ['nullable', Rule::in(['employed', 'entrepreneur', 'studying', 'seeking', 'other'])], 'company_name' => ['nullable', 'string', 'max:180'], 'position' => ['nullable', 'string', 'max:180'], 'industry' => ['nullable', 'string', 'max:180'], 'employment_started_on' => ['nullable', 'date', 'before_or_equal:today'], 'directory_consent' => ['required', 'boolean']];
    }
}
