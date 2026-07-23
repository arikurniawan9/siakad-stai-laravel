<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class AcademicProjectLogbookRequest extends FormRequest
{
    public function authorize(): bool { return true; }
    public function rules(): array
    {
        return ['activity_on' => ['required', 'date', 'before_or_equal:today'], 'hours' => ['nullable', 'numeric', 'min:0.25', 'max:24'], 'activity' => ['required', 'string', 'min:10', 'max:5000'], 'progress' => ['required', 'string', 'min:10', 'max:5000'], 'obstacles' => ['nullable', 'string', 'max:5000'], 'next_plan' => ['nullable', 'string', 'max:5000']];
    }
}
