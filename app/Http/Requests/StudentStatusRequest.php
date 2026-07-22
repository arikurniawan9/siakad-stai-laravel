<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StudentStatusRequest extends FormRequest
{
    public function authorize(): bool { return (bool) $this->user()?->can('students.update'); }

    public function rules(): array
    {
        return [
            'status' => ['required', Rule::in(['Aktif', 'Cuti', 'Lulus', 'Nonaktif'])],
            'academic_term_id' => ['nullable', 'integer', Rule::exists('academic_terms', 'id')->whereNull('deleted_at')],
            'effective_on' => ['required', 'date'],
            'reason' => ['required', 'string', 'min:5', 'max:1000'],
        ];
    }
}
