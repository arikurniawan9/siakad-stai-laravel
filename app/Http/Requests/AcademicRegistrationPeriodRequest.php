<?php

namespace App\Http\Requests;

use App\Models\SemesterRegistration;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

final class AcademicRegistrationPeriodRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('managePeriod', SemesterRegistration::class);
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['is_open' => $this->boolean('is_open')]);
        $this->merge(['is_changes_open' => $this->boolean('is_changes_open'), 'changes_starts_at' => $this->filled('changes_starts_at') ? $this->input('changes_starts_at') : null, 'changes_ends_at' => $this->filled('changes_ends_at') ? $this->input('changes_ends_at') : null]);
    }

    public function rules(): array
    {
        $period = $this->route('period');

        return [
            'academic_term_id' => ['required', 'integer', Rule::exists('academic_terms', 'id')->whereNull('deleted_at'), Rule::unique('academic_registration_periods')->ignore($period?->id)],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
            'changes_starts_at' => ['nullable', 'required_if:is_changes_open,true', 'date'],
            'changes_ends_at' => ['nullable', 'required_if:is_changes_open,true', 'date', 'after:changes_starts_at'],
            'default_max_credits' => ['required', 'integer', 'min:1', 'max:30'],
            'is_open' => ['required', 'boolean'],
            'is_changes_open' => ['required', 'boolean'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            $period = $this->route('period');
            if ($period instanceof \App\Models\AcademicRegistrationPeriod
                && $period->academic_term_id !== $this->integer('academic_term_id')
                && $period->registrations()->exists()) {
                $validator->errors()->add('academic_term_id', 'Semester tidak dapat diubah setelah periode memiliki registrasi mahasiswa.');
            }
        }];
    }
}
