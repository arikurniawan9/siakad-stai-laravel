<?php

namespace App\Http\Requests;

use App\Models\Lecturer;
use App\Models\Student;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StudentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $action = $this->isMethod('post') ? 'create' : 'update';

        return (bool) $this->user()?->can('students.'.$action);
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'nim' => strtoupper(trim((string) $this->input('nim'))),
            'academic_advisor_id' => $this->filled('academic_advisor_id') ? $this->input('academic_advisor_id') : null,
            'admission_term_id' => $this->filled('admission_term_id') ? $this->input('admission_term_id') : null,
            'gender' => $this->filled('gender') ? $this->input('gender') : null,
            'birth_date' => $this->filled('birth_date') ? $this->input('birth_date') : null,
        ]);
    }

    public function rules(): array
    {
        $student = $this->route('student');
        $id = $student instanceof Student ? $student->id : null;

        return [
            'user_id' => ['required', 'integer', Rule::exists('users', 'id')->where('is_active', true), Rule::unique('students', 'user_id')->ignore($id)],
            'program_id' => ['required', 'integer', Rule::exists('programs', 'id')->whereNull('deleted_at')->where('is_active', true)],
            'academic_advisor_id' => ['nullable', 'integer', Rule::exists('lecturers', 'id')->whereNull('deleted_at')->where('is_active', true)],
            'admission_term_id' => ['nullable', 'integer', Rule::exists('academic_terms', 'id')->whereNull('deleted_at')],
            'nim' => ['required', 'string', 'max:30', 'alpha_dash', Rule::unique('students', 'nim')->ignore($id)],
            'cohort_year' => ['required', 'integer', 'min:2000', 'max:'.(now()->year + 1)],
            'registration_type' => ['required', Rule::in(['Reguler', 'Transfer', 'Pindahan'])],
            'gender' => ['nullable', Rule::in(['L', 'P'])],
            'birth_date' => ['nullable', 'date', 'before:today'],
            'phone' => ['nullable', 'string', 'max:30'],
            'address' => ['nullable', 'string', 'max:1000'],
            'current_semester' => ['required', 'integer', 'min:1', 'max:20'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty() || ! $this->filled('academic_advisor_id')) return;
            $advisor = Lecturer::query()->find($this->integer('academic_advisor_id'));
            if ($advisor && $advisor->program_id !== $this->integer('program_id')) {
                $validator->errors()->add('academic_advisor_id', 'Dosen wali harus berasal dari program studi mahasiswa.');
            }
        }];
    }
}
