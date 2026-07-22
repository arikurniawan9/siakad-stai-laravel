<?php

namespace App\Http\Requests;

use App\Models\AcademicTerm;
use App\Models\Campus;
use App\Models\Course;
use App\Models\Faculty;
use App\Models\Program;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class MasterDataRequest extends FormRequest
{
    public function authorize(): bool
    {
        $class = $this->modelFor((string) $this->route('resource'));
        $action = $this->isMethod('post') ? 'create' : 'update';

        if (! $class) return false;

        return (bool) $this->user()?->can($action, $action === 'create' ? $class : new $class);
    }

    public function rules(): array
    {
        $resource = (string) $this->route('resource');
        $id = $this->route('id');

        return match ($resource) {
            'campuses' => [
                'name' => ['required', 'string', 'max:120'],
                'code' => ['required', 'string', 'max:30', 'alpha_dash', Rule::unique('campuses', 'code')->ignore($id)],
                'address' => ['nullable', 'string', 'max:255'],
                'is_active' => ['sometimes', 'boolean'],
            ],
            'faculties' => [
                'campus_id' => ['nullable', 'integer', 'exists:campuses,id'],
                'name' => ['required', 'string', 'max:120'],
                'code' => ['required', 'string', 'max:30', 'alpha_dash', Rule::unique('faculties', 'code')->ignore($id)],
            ],
            'programs' => [
                'faculty_id' => ['nullable', 'integer', 'exists:faculties,id'],
                'name' => ['required', 'string', 'max:120'],
                'code' => ['required', 'string', 'max:30', 'alpha_dash', Rule::unique('programs', 'code')->ignore($id)],
                'degree' => ['required', 'string', 'max:20'],
                'is_active' => ['sometimes', 'boolean'],
            ],
            'academic-terms' => [
                'name' => ['required', 'string', 'max:120'],
                'code' => ['required', 'string', 'max:30', Rule::unique('academic_terms', 'code')->ignore($id)],
                'semester' => ['required', Rule::in(['Ganjil', 'Genap', 'Pendek'])],
                'starts_on' => ['nullable', 'date'],
                'ends_on' => ['nullable', 'date', 'after_or_equal:starts_on'],
                'is_active' => ['sometimes', 'boolean'],
            ],
            'courses' => [
                'program_id' => ['nullable', 'integer', 'exists:programs,id'],
                'code' => ['required', 'string', 'max:30', 'alpha_dash', Rule::unique('courses', 'code')->ignore($id)],
                'name' => ['required', 'string', 'max:160'],
                'credits' => ['required', 'integer', 'min:1', 'max:12'],
                'type' => ['required', Rule::in(['Wajib', 'Pilihan'])],
                'is_active' => ['sometimes', 'boolean'],
            ],
            default => [],
        };
    }

    private function modelFor(string $resource): ?string
    {
        return match ($resource) {
            'campuses' => Campus::class,
            'faculties' => Faculty::class,
            'programs' => Program::class,
            'academic-terms' => AcademicTerm::class,
            'courses' => Course::class,
            default => null,
        };
    }
}
