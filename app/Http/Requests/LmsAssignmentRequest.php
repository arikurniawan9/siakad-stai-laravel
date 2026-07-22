<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class LmsAssignmentRequest extends FormRequest
{
    public function authorize(): bool { return true; }
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:180'], 'instructions' => ['nullable', 'string', 'max:20000'],
            'due_at' => ['required', 'date'], 'max_points' => ['required', 'numeric', 'min:1', 'max:10000'],
            'external_url' => ['nullable', 'url:http,https', 'max:1000'],
            'attachment' => ['nullable', 'file', 'max:10240', 'mimes:pdf,doc,docx,ppt,pptx,xls,xlsx,txt,jpg,jpeg,png,zip'],
            'remove_attachment' => ['sometimes', 'boolean'], 'is_published' => ['required', 'boolean'],
        ];
    }
}
