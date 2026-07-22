<?php

namespace App\Http\Requests;

use App\Models\ClassGroup;
use Illuminate\Foundation\Http\FormRequest;

final class GradeScoresRequest extends FormRequest
{
    public function authorize(): bool
    {
        $classGroup = $this->route('classGroup');

        return $classGroup instanceof ClassGroup && (bool) $this->user()?->can('manageGrades', $classGroup);
    }

    public function rules(): array
    {
        return ['scores' => ['required', 'array', 'min:1'], 'scores.*' => ['required', 'numeric', 'min:0', 'max:1000']];
    }
}
