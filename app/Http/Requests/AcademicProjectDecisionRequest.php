<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

final class AcademicProjectDecisionRequest extends FormRequest
{
    public function authorize(): bool { return true; }
    public function rules(): array
    {
        return ['decision' => ['required', Rule::in(['approve', 'revision', 'reject'])], 'notes' => ['nullable', 'string', 'max:5000']];
    }
    public function after(): array
    {
        return [function (Validator $validator): void {
            if (in_array($this->input('decision'), ['revision', 'reject'], true) && mb_strlen(trim((string) $this->input('notes'))) < 10) $validator->errors()->add('notes', 'Catatan minimal 10 karakter wajib untuk revisi atau penolakan.');
        }];
    }
}
