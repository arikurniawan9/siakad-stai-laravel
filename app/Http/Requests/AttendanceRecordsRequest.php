<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class AttendanceRecordsRequest extends FormRequest
{
    public function authorize(): bool { return true; }
    public function rules(): array { return ['records' => ['required', 'array', 'min:1', 'max:200'], 'records.*.id' => ['required', 'integer', 'distinct', 'exists:attendance_records,id'], 'records.*.status' => ['required', Rule::in(['present', 'late', 'sick', 'excused', 'absent'])], 'records.*.notes' => ['nullable', 'string', 'max:1000']]; }
}
