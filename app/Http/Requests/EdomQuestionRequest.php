<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class EdomQuestionRequest extends FormRequest
{
    public function authorize(): bool { return true; }
    public function rules(): array { return ['category' => ['required', 'string', 'max:100'], 'question' => ['required', 'string', 'max:1000'], 'type' => ['required', Rule::in(['rating', 'essay'])], 'sort_order' => ['required', 'integer', 'min:0', 'max:1000'], 'is_required' => ['required', 'boolean']]; }
}
