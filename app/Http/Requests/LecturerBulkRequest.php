<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class LecturerBulkRequest extends FormRequest
{
    public function authorize(): bool
    {
        $permission = $this->input('action') === 'restore' ? 'update' : 'delete';

        return (bool) $this->user()?->can('lecturers.'.$permission);
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['ids' => array_values(array_unique(array_map('intval', (array) $this->input('ids', []))))]);
    }

    public function rules(): array
    {
        return [
            'action' => ['required', Rule::in(['archive', 'restore'])],
            'ids' => ['required', 'array', 'min:1', 'max:100'],
            'ids.*' => ['required', 'integer', 'min:1'],
        ];
    }
}
