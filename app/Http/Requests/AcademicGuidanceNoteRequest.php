<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class AcademicGuidanceNoteRequest extends FormRequest
{
    public function authorize(): bool { return $this->user() !== null; }
    public function rules(): array { return ['student_id' => ['required', 'integer', 'exists:students,id'], 'appointment_id' => ['nullable', 'integer', 'exists:academic_guidance_appointments,id'], 'title' => ['required', 'string', 'max:180'], 'content' => ['required', 'string', 'max:10000'], 'follow_up_due' => ['nullable', 'date'], 'follow_up_status' => ['required', 'in:none,pending,completed']]; }
}
