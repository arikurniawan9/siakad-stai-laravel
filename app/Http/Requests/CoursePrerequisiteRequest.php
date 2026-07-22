<?php

namespace App\Http\Requests;

use App\Models\Course;
use App\Models\CoursePrerequisite;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class CoursePrerequisiteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('curricula.update');
    }

    public function rules(): array
    {
        return [
            'course_id' => ['required', 'integer', Rule::exists('courses', 'id')->whereNull('deleted_at')->where('is_active', true)],
            'prerequisite_course_id' => [
                'required',
                'integer',
                'different:course_id',
                Rule::exists('courses', 'id')->whereNull('deleted_at')->where('is_active', true),
                Rule::unique('course_prerequisites', 'prerequisite_course_id')->where(fn ($query) => $query->where('course_id', $this->integer('course_id'))),
            ],
            'minimum_grade' => ['required', Rule::in(['A', 'B+', 'B', 'C+', 'C', 'D'])],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $course = Course::query()->find($this->integer('course_id'));
            $prerequisite = Course::query()->find($this->integer('prerequisite_course_id'));
            if ($course && $prerequisite && $course->program_id !== $prerequisite->program_id) {
                $validator->errors()->add('prerequisite_course_id', 'Mata kuliah prasyarat harus berasal dari program studi yang sama.');
                return;
            }

            if ($this->createsCycle($this->integer('course_id'), $this->integer('prerequisite_course_id'))) {
                $validator->errors()->add('prerequisite_course_id', 'Relasi prasyarat akan membentuk siklus.');
            }
        }];
    }

    private function createsCycle(int $courseId, int $prerequisiteId): bool
    {
        $frontier = [$prerequisiteId];
        $visited = [];

        while ($frontier !== []) {
            $current = array_pop($frontier);
            if ($current === $courseId) {
                return true;
            }
            if (isset($visited[$current])) {
                continue;
            }
            $visited[$current] = true;
            $frontier = [
                ...$frontier,
                ...CoursePrerequisite::query()->where('course_id', $current)->pluck('prerequisite_course_id')->map(fn ($id) => (int) $id)->all(),
            ];
        }

        return false;
    }
}
