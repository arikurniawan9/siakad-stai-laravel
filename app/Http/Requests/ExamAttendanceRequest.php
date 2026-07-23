<?php

namespace App\Http\Requests;

use App\Models\ExamParticipant;
use App\Models\ExamSchedule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

final class ExamAttendanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        $exam = $this->route('exam');

        return $exam instanceof ExamSchedule && ($this->user()?->can('operate', $exam) ?? false);
    }

    public function rules(): array
    {
        return [
            'participants' => ['required', 'array', 'min:1', 'max:500'],
            'participants.*.id' => ['required', 'integer', 'distinct', 'exists:exam_participants,id'],
            'participants.*.attendance_status' => ['required', Rule::in(['present', 'absent', 'sick', 'excused'])],
            'participants.*.notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) return;
            $exam = $this->route('exam');
            $ids = collect($this->input('participants'))->pluck('id')->map(fn ($id) => (int) $id);
            $matched = ExamParticipant::query()->where('exam_schedule_id', $exam->id)->whereIn('id', $ids)->count();
            if ($matched !== $ids->count()) $validator->errors()->add('participants', 'Daftar hadir memuat peserta dari ujian lain.');
        }];
    }
}
