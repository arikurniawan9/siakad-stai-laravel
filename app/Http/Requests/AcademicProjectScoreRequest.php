<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class AcademicProjectScoreRequest extends FormRequest
{
    public function authorize(): bool { return true; }
    public function rules(): array
    {
        return ['scores' => ['required', 'array', 'min:1', 'max:20'], 'scores.*.rubric_item_id' => ['required', 'integer', 'distinct', 'exists:academic_project_rubric_items,id'], 'scores.*.score' => ['required', 'numeric', 'min:0'], 'scores.*.notes' => ['nullable', 'string', 'max:2000']];
    }
}
