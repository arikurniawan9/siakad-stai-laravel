<?php

namespace App\Http\Requests;

use App\Models\Course;
use App\Models\Curriculum;
use App\Models\CurriculumCourse;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class CurriculumCourseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('curricula.update');
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['is_required' => $this->boolean('is_required')]);
    }

    public function rules(): array
    {
        $curriculum = $this->route('curriculum');
        $item = $this->route('item');
        $curriculumId = $curriculum instanceof Curriculum ? $curriculum->id : null;
        $itemId = $item instanceof CurriculumCourse ? $item->id : null;

        return [
            'course_id' => [
                'required',
                'integer',
                Rule::exists('courses', 'id')->whereNull('deleted_at')->where('is_active', true),
                Rule::unique('curriculum_courses', 'course_id')->where(fn ($query) => $query->where('curriculum_id', $curriculumId))->ignore($itemId),
            ],
            'semester' => ['required', 'integer', 'min:1', 'max:14'],
            'credits' => ['required', 'integer', 'min:1', 'max:12'],
            'is_required' => ['required', 'boolean'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $curriculum = $this->route('curriculum');
            $course = Course::query()->find($this->integer('course_id'));
            if ($curriculum instanceof Curriculum && $course && $curriculum->program_id !== $course->program_id) {
                $validator->errors()->add('course_id', 'Mata kuliah harus berasal dari program studi kurikulum yang sama.');
            }
        }];
    }
}
