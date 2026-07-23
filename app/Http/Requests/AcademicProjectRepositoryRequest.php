<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class AcademicProjectRepositoryRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $keywords = is_string($this->input('keywords')) ? array_values(array_filter(array_map('trim', explode(',', $this->input('keywords'))))) : $this->input('keywords');
        $this->merge(['keywords' => $keywords]);
    }
    public function authorize(): bool { return true; }
    public function rules(): array
    {
        return ['title' => ['required', 'string', 'min:10', 'max:250'], 'abstract' => ['required', 'string', 'min:50', 'max:15000'], 'keywords' => ['required', 'array', 'min:3', 'max:8'], 'keywords.*' => ['required', 'string', 'distinct', 'max:60'], 'publication_consent' => ['required', 'boolean']];
    }
}
