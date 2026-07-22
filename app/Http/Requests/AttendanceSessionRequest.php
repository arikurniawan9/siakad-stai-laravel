<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class AttendanceSessionRequest extends FormRequest
{
    public function authorize(): bool { return true; }
    public function rules(): array { return ['meeting_number' => ['required', 'integer', 'between:1,30'], 'starts_at' => ['required', 'date'], 'ends_at' => ['required', 'date', 'after:starts_at'], 'topic' => ['required', 'string', 'max:180'], 'notes' => ['nullable', 'string', 'max:5000'], 'delivery_mode' => ['required', Rule::in(['onsite', 'online', 'hybrid'])], 'access_code' => ['nullable', 'digits_between:4,8']]; }
}
