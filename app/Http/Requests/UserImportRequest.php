<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class UserImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('users.update');
    }

    public function rules(): array
    {
        return ['file' => ['required', 'file', 'max:2048', 'mimes:csv,txt']];
    }
}
