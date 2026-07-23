<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class GraduationDocumentRequest extends FormRequest
{
    public function authorize(): bool { return true; }
    public function rules(): array { return ['document_type' => ['required', Rule::in(['identity', 'photo', 'clearance'])], 'document' => ['required', 'file', 'max:5120', 'mimes:pdf,jpg,jpeg,png']]; }
}
