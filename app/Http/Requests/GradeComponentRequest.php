<?php

namespace App\Http\Requests;

use App\Models\ClassGroup;
use App\Models\GradeComponent;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class GradeComponentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $classGroup = $this->route('classGroup');

        return $classGroup instanceof ClassGroup && (bool) $this->user()?->can('manageGrades', $classGroup);
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['name' => trim((string) $this->input('name'))]);
    }

    public function rules(): array
    {
        $classGroup = $this->route('classGroup');
        $component = $this->route('component');
        $sheetId = $classGroup instanceof ClassGroup ? $classGroup->gradeSheet?->id : null;

        return [
            'name' => ['required', 'string', 'max:100', Rule::unique('grade_components', 'name')->where('grade_sheet_id', $sheetId)->ignore($component instanceof GradeComponent ? $component->id : null)],
            'weight' => ['required', 'numeric', 'min:0.01', 'max:100'],
            'max_score' => ['required', 'numeric', 'min:0.01', 'max:1000'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:1000'],
        ];
    }
}
