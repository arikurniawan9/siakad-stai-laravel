<?php
namespace App\Http\Requests;
use Illuminate\Foundation\Http\FormRequest;
final class StudentInterventionRequest extends FormRequest { public function authorize(): bool { return $this->user() !== null; } public function rules(): array { return ['student_id' => ['required', 'exists:students,id'], 'warning_id' => ['nullable', 'exists:student_early_warnings,id'], 'title' => ['required', 'string', 'max:180'], 'action_plan' => ['required', 'string', 'max:5000'], 'due_on' => ['nullable', 'date'], 'assigned_lecturer_id' => ['nullable', 'exists:lecturers,id']]; } }
