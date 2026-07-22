<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class BillingItemRequest extends FormRequest
{
    public function authorize(): bool { return true; }
    public function rules(): array { return ['student_id' => ['required', 'integer', 'exists:students,id'], 'academic_term_id' => ['required', 'integer', 'exists:academic_terms,id'], 'description' => ['required', 'string', 'max:180'], 'category' => ['required', 'string', 'max:80'], 'amount' => ['required', 'numeric', 'min:1', 'max:999999999999'], 'due_on' => ['required', 'date'], 'notes' => ['nullable', 'string', 'max:5000']]; }
}
