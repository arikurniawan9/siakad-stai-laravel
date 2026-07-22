<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class LmsSubmissionRequest extends FormRequest
{
    public function authorize(): bool { return true; }
    public function rules(): array
    {
        return [
            'answer_text' => ['nullable', 'string', 'max:30000', 'required_without_all:external_url,attachment'],
            'external_url' => ['nullable', 'url:http,https', 'max:1000', 'required_without_all:answer_text,attachment'],
            'attachment' => ['nullable', 'file', 'max:15360', 'mimes:pdf,doc,docx,ppt,pptx,xls,xlsx,txt,jpg,jpeg,png,zip', 'required_without_all:answer_text,external_url'],
        ];
    }
}
