<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class AcademicProjectDocumentRequest extends FormRequest
{
    public function authorize(): bool { return true; }
    public function rules(): array
    {
        return [
            'document_type' => ['required', Rule::in(['proposal', 'eligibility', 'supporting', 'revision', 'final_report', 'repository'])],
            'document' => ['required', 'file', 'max:10240', 'mimes:pdf,doc,docx,jpg,jpeg,png'],
        ];
    }
}
