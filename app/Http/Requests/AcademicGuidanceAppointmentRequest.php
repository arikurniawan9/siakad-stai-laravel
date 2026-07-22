<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class AcademicGuidanceAppointmentRequest extends FormRequest
{
    public function authorize(): bool { return $this->user() !== null; }
    public function rules(): array
    {
        return ['student_id' => ['nullable', 'integer', 'exists:students,id'], 'starts_at' => ['required', 'date', 'after:now'], 'ends_at' => ['required', 'date', 'after:starts_at'], 'mode' => ['required', Rule::in(['online', 'onsite', 'phone'])], 'location' => ['nullable', 'string', 'max:180'], 'agenda' => ['required', 'string', 'max:180'], 'student_notes' => ['nullable', 'string', 'max:3000']];
    }
}
