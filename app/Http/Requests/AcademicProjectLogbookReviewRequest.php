<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

final class AcademicProjectLogbookReviewRequest extends FormRequest
{
    public function authorize(): bool { return true; }
    public function rules(): array { return ['decision' => ['required', Rule::in(['verify', 'revision'])], 'notes' => ['nullable', 'string', 'max:5000']]; }
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($this->input('decision') === 'revision' && mb_strlen(trim((string) $this->input('notes'))) < 10) $validator->errors()->add('notes', 'Catatan minimal 10 karakter wajib untuk permintaan revisi.');
        }];
    }
}
