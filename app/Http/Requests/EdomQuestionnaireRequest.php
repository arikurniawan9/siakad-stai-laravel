<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class EdomQuestionnaireRequest extends FormRequest
{
    public function authorize(): bool { return true; }
    public function rules(): array { return ['academic_term_id' => ['required', 'integer', 'exists:academic_terms,id'], 'title' => ['required', 'string', 'max:180'], 'description' => ['nullable', 'string', 'max:10000'], 'starts_at' => ['required', 'date'], 'ends_at' => ['required', 'date', 'after:starts_at'], 'is_active' => ['required', 'boolean'], 'include_default_questions' => ['sometimes', 'boolean']]; }
}
