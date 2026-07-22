<?php

namespace App\Http\Requests;

use App\Models\Curriculum;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class CurriculumRequest extends FormRequest
{
    public function authorize(): bool
    {
        $action = $this->isMethod('post') ? 'create' : 'update';

        return (bool) $this->user()?->can('curricula.'.$action);
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'code' => strtoupper(trim((string) $this->input('code'))),
            'is_active' => $this->boolean('is_active'),
            'effective_term_id' => $this->filled('effective_term_id') ? $this->input('effective_term_id') : null,
        ]);
    }

    public function rules(): array
    {
        $curriculum = $this->route('curriculum');
        $id = $curriculum instanceof Curriculum ? $curriculum->id : null;

        return [
            'program_id' => ['required', 'integer', Rule::exists('programs', 'id')->whereNull('deleted_at')->where('is_active', true)],
            'effective_term_id' => ['nullable', 'integer', Rule::exists('academic_terms', 'id')->whereNull('deleted_at')],
            'name' => ['required', 'string', 'max:150'],
            'code' => [
                'required',
                'string',
                'max:30',
                'alpha_dash',
                Rule::unique('curricula', 'code')
                    ->where(fn ($query) => $query->where('program_id', $this->integer('program_id')))
                    ->ignore($id),
            ],
            'target_credits' => ['required', 'integer', 'min:1', 'max:300'],
            'description' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['required', 'boolean'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $curriculum = $this->route('curriculum');
            if ($curriculum instanceof Curriculum
                && $curriculum->program_id !== $this->integer('program_id')
                && $curriculum->curriculumCourses()->exists()) {
                $validator->errors()->add('program_id', 'Program studi tidak dapat diubah setelah kurikulum memiliki mata kuliah.');
            }
        }];
    }
}
