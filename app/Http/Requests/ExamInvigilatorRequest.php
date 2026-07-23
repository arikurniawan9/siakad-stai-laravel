<?php

namespace App\Http\Requests;

use App\Models\ExamSchedule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

final class ExamInvigilatorRequest extends FormRequest
{
    public function authorize(): bool
    {
        $exam = $this->route('exam');

        return $exam instanceof ExamSchedule && ($this->user()?->can('assign', $exam) ?? false);
    }

    public function rules(): array
    {
        return [
            'lecturer_ids' => ['required', 'array', 'min:1', 'max:10'],
            'lecturer_ids.*' => ['required', 'integer', 'distinct', Rule::exists('lecturers', 'id')->whereNull('deleted_at')->where('is_active', true)],
            'coordinator_id' => ['required', 'integer'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if (! in_array($this->integer('coordinator_id'), array_map('intval', $this->input('lecturer_ids', [])), true)) {
                $validator->errors()->add('coordinator_id', 'Koordinator harus dipilih dari daftar pengawas.');
            }
        }];
    }
}
