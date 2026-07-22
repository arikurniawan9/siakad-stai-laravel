<?php

namespace App\Http\Requests\Pmb;

use App\Models\PmbFee;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class PmbFeeRequest extends FormRequest
{
    public function authorize(): bool
    {
        $fee = $this->route('fee');

        return (bool) $this->user()?->can($this->isMethod('post') ? 'create' : 'update', $this->isMethod('post') ? PmbFee::class : $fee);
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'program_id' => $this->filled('program_id') ? $this->integer('program_id') : null,
            'wave' => $this->filled('wave') ? trim((string) $this->input('wave')) : null,
            'is_active' => $this->boolean('is_active'),
        ]);
    }

    public function rules(): array
    {
        return [
            'academic_term_id' => ['required', 'integer', 'exists:academic_terms,id'],
            'program_id' => ['nullable', 'integer', Rule::exists('programs', 'id')->whereNull('deleted_at')],
            'name' => ['required', 'string', 'min:3', 'max:255'],
            'registration_path' => ['required', Rule::in(['Semua', 'Reguler', 'Prestasi', 'Transfer'])],
            'registration_type' => ['required', Rule::in(['Semua', 'Baru', 'Pindahan'])],
            'wave' => ['nullable', 'string', 'max:50'],
            'amount' => ['required', 'numeric', 'min:0', 'max:9999999999999.99'],
            'starts_on' => ['nullable', 'date'],
            'ends_on' => ['nullable', 'date', 'after_or_equal:starts_on'],
            'due_days' => ['required', 'integer', 'min:1', 'max:60'],
            'is_active' => ['required', 'boolean'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
