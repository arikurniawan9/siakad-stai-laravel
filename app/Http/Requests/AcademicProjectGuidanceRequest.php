<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class AcademicProjectGuidanceRequest extends FormRequest
{
    public function authorize(): bool { return true; }
    public function rules(): array
    {
        return ['occurred_at' => ['required', 'date', 'before_or_equal:now'], 'mode' => ['required', Rule::in(['onsite', 'online', 'phone'])], 'discussion' => ['required', 'string', 'min:10', 'max:5000'], 'feedback' => ['required', 'string', 'min:10', 'max:5000'], 'follow_up' => ['nullable', 'string', 'max:5000']];
    }
}
