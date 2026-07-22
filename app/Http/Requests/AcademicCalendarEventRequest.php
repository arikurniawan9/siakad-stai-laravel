<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class AcademicCalendarEventRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can($this->isMethod('post') ? 'calendar.create' : 'calendar.update') ?? false;
    }

    public function rules(): array
    {
        return [
            'academic_term_id' => ['nullable', 'integer', Rule::exists('academic_terms', 'id')->whereNull('deleted_at')],
            'title' => ['required', 'string', 'max:180'],
            'event_type' => ['required', Rule::in(['academic', 'registration', 'holiday', 'announcement', 'other'])],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
            'description' => ['nullable', 'string', 'max:5000'],
            'location' => ['nullable', 'string', 'max:180'],
            'is_published' => ['required', 'boolean'],
        ];
    }
}
