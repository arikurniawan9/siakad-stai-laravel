<?php

namespace App\Http\Requests;

use App\Models\ExamSchedule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

final class ExamScheduleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can($this->isMethod('post') ? 'exams.create' : 'exams.update') ?? false;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['room_id' => filled($this->input('room_id')) ? $this->integer('room_id') : null]);
    }

    public function rules(): array
    {
        $exam = $this->route('exam');
        $id = $exam instanceof ExamSchedule ? $exam->id : null;

        return [
            'academic_term_id' => ['required', 'integer', Rule::exists('academic_terms', 'id')->whereNull('deleted_at')],
            'class_group_id' => ['required', 'integer', Rule::exists('class_groups', 'id')->whereNull('deleted_at')],
            'exam_type' => ['required', Rule::in(['uts', 'uas'])],
            'exam_date' => ['required', 'date'],
            'starts_at' => ['required', 'date_format:H:i'],
            'ends_at' => ['required', 'date_format:H:i', 'after:starts_at'],
            'room_id' => ['nullable', 'integer', Rule::exists('rooms', 'id')->whereNull('deleted_at')->where('is_active', true)],
            'delivery_mode' => ['required', Rule::in(['onsite', 'online', 'hybrid'])],
            'status' => ['required', Rule::in(['draft', 'published', 'cancelled'])],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) return;
            if ($this->input('delivery_mode') !== 'online' && ! $this->integer('room_id')) $validator->errors()->add('room_id', 'Ruangan wajib diisi untuk ujian luring atau hybrid.');
            if ($this->input('delivery_mode') === 'online' && $this->integer('room_id')) $validator->errors()->add('room_id', 'Ujian daring tidak memerlukan ruangan.');
            $exam = $this->route('exam');
            if ($exam instanceof ExamSchedule && $exam->class_group_id !== $this->integer('class_group_id') && $exam->status === 'published') $validator->errors()->add('class_group_id', 'Ujian yang sudah dipublikasikan tidak dapat dipindahkan ke kelas lain.');
        }];
    }
}
