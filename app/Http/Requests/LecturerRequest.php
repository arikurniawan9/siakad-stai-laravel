<?php

namespace App\Http\Requests;

use App\Models\Lecturer;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class LecturerRequest extends FormRequest
{
    public function authorize(): bool
    {
        $action = $this->isMethod('post') ? 'create' : 'update';

        return (bool) $this->user()?->can('lecturers.'.$action);
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'nidn' => strtoupper(trim((string) $this->input('nidn'))),
            'employee_number' => $this->filled('employee_number') ? strtoupper(trim((string) $this->input('employee_number'))) : null,
            'user_id' => $this->filled('user_id') ? $this->input('user_id') : null,
            'is_active' => $this->boolean('is_active'),
        ]);
    }

    public function rules(): array
    {
        $lecturer = $this->route('lecturer');
        $id = $lecturer instanceof Lecturer ? $lecturer->id : null;

        return [
            'user_id' => ['nullable', 'integer', Rule::exists('users', 'id')->where('is_active', true), Rule::unique('lecturers', 'user_id')->ignore($id)],
            'program_id' => ['required', 'integer', Rule::exists('programs', 'id')->whereNull('deleted_at')->where('is_active', true)],
            'name' => ['required', 'string', 'max:150'],
            'nidn' => ['required', 'string', 'max:30', 'regex:/^[0-9A-Z.\/-]+$/', Rule::unique('lecturers', 'nidn')->ignore($id)],
            'employee_number' => ['nullable', 'string', 'max:50', Rule::unique('lecturers', 'employee_number')->ignore($id)],
            'academic_title' => ['nullable', 'string', 'max:80'],
            'employment_status' => ['required', Rule::in(['Tetap', 'Tidak Tetap'])],
            'expertise' => ['nullable', 'string', 'max:160'],
            'is_active' => ['required', 'boolean'],
        ];
    }
}
