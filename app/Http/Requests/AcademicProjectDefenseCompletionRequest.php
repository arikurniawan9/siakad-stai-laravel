<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class AcademicProjectDefenseCompletionRequest extends FormRequest
{
    public function authorize(): bool { return true; }
    public function rules(): array
    {
        return ['result' => ['required', Rule::in(['passed', 'revision', 'failed'])], 'minutes_summary' => ['required', 'string', 'min:20', 'max:10000'], 'incidents' => ['nullable', 'string', 'max:5000']];
    }
}
