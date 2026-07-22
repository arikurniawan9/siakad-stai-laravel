<?php

namespace App\Http\Requests\Pmb;

use App\Models\PmbSelection;
use Illuminate\Foundation\Http\FormRequest;

final class PmbSelectionComponentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $selection = $this->route('selection');

        return $selection instanceof PmbSelection && (bool) $this->user()?->can('update', $selection);
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['name' => trim((string) $this->input('name'))]);
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'weight' => ['required', 'numeric', 'gt:0', 'max:100'],
            'max_score' => ['required', 'numeric', 'gt:0', 'max:10000'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:1000'],
        ];
    }
}
