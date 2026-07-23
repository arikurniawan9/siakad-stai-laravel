<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

final class AcademicProjectRubricRequest extends FormRequest
{
    public function authorize(): bool { return true; }
    public function rules(): array
    {
        return ['items' => ['required', 'array', 'min:1', 'max:20'], 'items.*.name' => ['required', 'string', 'distinct', 'max:180'], 'items.*.weight' => ['required', 'numeric', 'gt:0', 'max:100'], 'items.*.max_score' => ['required', 'numeric', 'gt:0', 'max:1000']];
    }
    public function after(): array
    {
        return [function (Validator $validator): void {
            $total = collect($this->input('items', []))->sum(fn ($item) => (float) ($item['weight'] ?? 0));
            if (abs($total - 100) > 0.001) $validator->errors()->add('items', 'Total bobot rubrik harus tepat 100%.');
        }];
    }
}
