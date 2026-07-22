<?php

namespace App\Http\Requests;

use App\Models\ClassGroup;
use Illuminate\Foundation\Http\FormRequest;

final class PublishGradeSheetRequest extends FormRequest
{
    public function authorize(): bool
    {
        $classGroup = $this->route('classGroup');

        return $classGroup instanceof ClassGroup && (bool) $this->user()?->can('manageGrades', $classGroup);
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['notes' => $this->filled('notes') ? trim((string) $this->input('notes')) : null]);
    }

    public function rules(): array
    {
        return ['notes' => ['nullable', 'string', 'max:2000']];
    }
}
