<?php

namespace App\Http\Requests;

use App\Models\AcademicTerm;
use App\Models\Building;
use App\Models\Campus;
use App\Models\Course;
use App\Models\Faculty;
use App\Models\Lecturer;
use App\Models\Program;
use App\Models\Room;
use App\Models\Student;
use Illuminate\Foundation\Http\FormRequest;

class MasterDataImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        $resource = match (true) {
            $this->routeIs('admin.lecturers.import.preview') => 'lecturers',
            $this->routeIs('admin.students.import.preview') => 'students',
            default => (string) $this->route('resource'),
        };
        $class = $this->modelFor($resource);
        if (! $class) return false;

        return (bool) $this->user()?->can('create', $class) && (bool) $this->user()?->can('update', new $class);
    }

    public function rules(): array
    {
        return ['file' => ['required', 'file', 'max:2048', 'mimes:csv,txt']];
    }

    private function modelFor(string $resource): ?string
    {
        return match ($resource) {
            'campuses' => Campus::class,
            'faculties' => Faculty::class,
            'programs' => Program::class,
            'academic-terms' => AcademicTerm::class,
            'courses' => Course::class,
            'buildings' => Building::class,
            'rooms' => Room::class,
            'lecturers' => Lecturer::class,
            'students' => Student::class,
            default => null,
        };
    }
}
