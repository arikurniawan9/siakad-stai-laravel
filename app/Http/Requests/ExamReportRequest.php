<?php

namespace App\Http\Requests;

use App\Models\ExamSchedule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ExamReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        $exam = $this->route('exam');

        return $exam instanceof ExamSchedule && ($this->user()?->can('operate', $exam) ?? false);
    }

    public function rules(): array
    {
        return [
            'status' => ['required', Rule::in(['draft', 'finalized'])],
            'actual_starts_at' => ['required', 'date'],
            'actual_ends_at' => ['required', 'date', 'after:actual_starts_at'],
            'material_summary' => ['required', 'string', 'max:5000'],
            'incidents' => ['nullable', 'string', 'max:5000'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
