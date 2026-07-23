<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

final class AcademicProjectAssignmentRequest extends FormRequest
{
    public function authorize(): bool { return true; }
    public function rules(): array
    {
        $exists = Rule::exists('lecturers', 'id')->whereNull('deleted_at')->where('is_active', true);

        return [
            'supervisor_ids' => ['required', 'array', 'min:1', 'max:2'],
            'supervisor_ids.*' => ['required', 'integer', 'distinct', $exists],
            'examiner_ids' => ['present', 'array', 'max:3'],
            'examiner_ids.*' => ['required', 'integer', 'distinct', Rule::exists('lecturers', 'id')->whereNull('deleted_at')->where('is_active', true)],
        ];
    }
    public function after(): array
    {
        return [function (Validator $validator): void {
            if (array_intersect(array_map('intval', $this->input('supervisor_ids', [])), array_map('intval', $this->input('examiner_ids', []))) !== []) $validator->errors()->add('examiner_ids', 'Dosen yang sama tidak boleh menjadi pembimbing sekaligus penguji.');
        }];
    }
}
