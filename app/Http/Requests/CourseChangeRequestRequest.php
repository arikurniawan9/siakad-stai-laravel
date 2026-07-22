<?php

namespace App\Http\Requests;

use App\Models\SemesterRegistration;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class CourseChangeRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        $registration = $this->route('registration');
        return $registration instanceof SemesterRegistration && (bool) $this->user()?->can('requestChange', $registration);
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['reason' => trim((string) $this->input('reason')), 'class_group_id' => $this->filled('class_group_id') ? $this->input('class_group_id') : null, 'course_enrollment_id' => $this->filled('course_enrollment_id') ? $this->input('course_enrollment_id') : null]);
    }

    public function rules(): array
    {
        return [
            'type' => ['required', Rule::in(['add', 'drop'])],
            'class_group_id' => ['nullable', 'required_if:type,add', 'integer', Rule::exists('class_groups', 'id')->whereNull('deleted_at')->where('is_active', true)],
            'course_enrollment_id' => ['nullable', 'required_if:type,drop', 'integer', 'exists:course_enrollments,id'],
            'reason' => ['required', 'string', 'min:10', 'max:2000'],
        ];
    }
}
