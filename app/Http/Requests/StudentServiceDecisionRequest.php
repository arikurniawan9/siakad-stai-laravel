<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StudentServiceDecisionRequest extends FormRequest
{
    public function authorize(): bool { return true; }
    public function rules(): array { return ['decision' => ['required', Rule::in(['approve', 'revision', 'reject'])], 'notes' => [Rule::requiredIf(fn () => in_array($this->input('decision'), ['revision', 'reject'], true)), 'nullable', 'string', 'max:5000']]; }
}
