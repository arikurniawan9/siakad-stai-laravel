<?php

namespace App\Http\Requests\Pmb;

use App\Models\PmbSelection;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class PmbSelectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        $selection = $this->route('selection');

        return $this->isMethod('post')
            ? (bool) $this->user()?->can('create', PmbSelection::class)
            : ($selection instanceof PmbSelection && (bool) $this->user()?->can('update', $selection));
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['name' => trim((string) $this->input('name')), 'program_id' => $this->filled('program_id') ? $this->input('program_id') : null]);
    }

    public function rules(): array
    {
        return [
            'academic_term_id' => ['required', 'integer', Rule::exists('academic_terms', 'id')->whereNull('deleted_at')],
            'program_id' => ['nullable', 'integer', Rule::exists('programs', 'id')->whereNull('deleted_at')->where('is_active', true)],
            'name' => ['required', 'string', 'max:150'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
            'passing_grade' => ['required', 'numeric', 'min:0', 'max:100'],
        ];
    }
}
