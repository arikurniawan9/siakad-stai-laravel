<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class GraduationPeriodRequest extends FormRequest
{
    public function authorize(): bool { return true; }
    public function rules(): array
    {
        $period = $this->route('period');

        return ['academic_term_id' => ['required', 'integer', Rule::exists('academic_terms', 'id')->whereNull('deleted_at')], 'code' => ['required', 'string', 'max:40', Rule::unique('graduation_periods', 'code')->ignore($period?->id)], 'name' => ['required', 'string', 'max:180'], 'registration_starts_at' => ['required', 'date'], 'registration_ends_at' => ['required', 'date', 'after:registration_starts_at'], 'judicium_on' => ['required', 'date', 'after_or_equal:registration_ends_at'], 'ceremony_on' => ['nullable', 'date', 'after_or_equal:judicium_on'], 'quota' => ['nullable', 'integer', 'min:1', 'max:10000'], 'is_active' => ['required', 'boolean']];
    }
}
