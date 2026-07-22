<?php

namespace App\Http\Requests;

use App\Models\SemesterRegistration;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class AddCourseEnrollmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $registration = $this->route('registration');

        return $registration instanceof SemesterRegistration && (bool) $this->user()?->can('update', $registration);
    }

    public function rules(): array
    {
        return ['class_group_id' => ['required', 'integer', Rule::exists('class_groups', 'id')->whereNull('deleted_at')->where('is_active', true)]];
    }
}
