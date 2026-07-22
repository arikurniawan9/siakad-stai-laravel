<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class FacilityBulkRequest extends FormRequest
{
    public function authorize(): bool
    {
        $resource = (string) $this->route('resource');
        $permission = $this->input('action') === 'restore' ? 'update' : 'delete';

        return in_array($resource, ['buildings', 'rooms'], true) && (bool) $this->user()?->can($resource.'.'.$permission);
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
