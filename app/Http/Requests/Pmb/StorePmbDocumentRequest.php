<?php

namespace App\Http\Requests\Pmb;

use App\Domain\Pmb\PmbApplicationWorkflowService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StorePmbDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'type' => ['required', Rule::in(PmbApplicationWorkflowService::REQUIRED_DOCUMENTS)],
            'file' => ['required', 'file', 'max:2048', 'mimes:pdf,jpg,jpeg,png'],
        ];
    }
}
