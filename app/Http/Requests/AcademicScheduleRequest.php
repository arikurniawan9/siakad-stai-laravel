<?php

namespace App\Http\Requests;

use App\Models\ClassGroup;
use App\Models\Room;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class AcademicScheduleRequest extends FormRequest
{
    public function authorize(): bool
    {
        $action = $this->isMethod('post') ? 'create' : 'update';

        return (bool) $this->user()?->can('schedules.'.$action);
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['is_active' => $this->boolean('is_active')]);
    }

    public function rules(): array
    {
        $schedule = $this->route('schedule');
        $id = $schedule instanceof ClassGroup ? $schedule->id : null;

        return [
            'academic_term_id' => ['required', 'integer', Rule::exists('academic_terms', 'id')->whereNull('deleted_at')],
            'course_id' => ['required', 'integer', Rule::exists('courses', 'id')->whereNull('deleted_at')->where('is_active', true)],
            'lecturer_id' => ['required', 'integer', Rule::exists('lecturers', 'id')->whereNull('deleted_at')->where('is_active', true)],
            'room_id' => ['required', 'integer', Rule::exists('rooms', 'id')->whereNull('deleted_at')->where('is_active', true)],
            'name' => [
                'required',
                'string',
                'max:80',
                Rule::unique('class_groups', 'name')
                    ->where(fn ($query) => $query
                        ->where('academic_term_id', $this->integer('academic_term_id'))
                        ->where('course_id', $this->integer('course_id')))
                    ->ignore($id),
            ],
            'capacity' => ['required', 'integer', 'min:1', 'max:10000'],
            'day' => ['required', Rule::in(['Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu', 'Minggu'])],
            'starts_at' => ['required', 'date_format:H:i'],
            'ends_at' => ['required', 'date_format:H:i', 'after:starts_at'],
            'is_active' => ['required', 'boolean'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $schedule = $this->route('schedule');
            $id = $schedule instanceof ClassGroup ? $schedule->id : null;
            $room = Room::query()->find($this->integer('room_id'));
            if ($room && $this->integer('capacity') > $room->capacity) {
                $validator->errors()->add('capacity', "Kapasitas kelas tidak boleh melebihi kapasitas ruangan ({$room->capacity}).");
            }
            if ($schedule instanceof ClassGroup && $this->integer('capacity') < $schedule->enrolled_count) {
                $validator->errors()->add('capacity', "Kapasitas tidak boleh kurang dari peserta terdaftar ({$schedule->enrolled_count}).");
            }

            $overlap = ClassGroup::query()
                ->where('academic_term_id', $this->integer('academic_term_id'))
                ->where('day', $this->input('day'))
                ->where('starts_at', '<', $this->input('ends_at'))
                ->where('ends_at', '>', $this->input('starts_at'))
                ->when($id, fn ($query) => $query->whereKeyNot($id));

            if ((clone $overlap)->where('room_id', $this->integer('room_id'))->exists()) {
                $validator->errors()->add('room_id', 'Ruangan sudah digunakan pada waktu yang beririsan.');
            }
            if ((clone $overlap)->where('lecturer_id', $this->integer('lecturer_id'))->exists()) {
                $validator->errors()->add('lecturer_id', 'Dosen sudah mengajar pada waktu yang beririsan.');
            }
        }];
    }
}
