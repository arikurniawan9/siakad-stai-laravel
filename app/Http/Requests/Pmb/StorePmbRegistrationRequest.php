<?php

namespace App\Http\Requests\Pmb;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePmbRegistrationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'full_name' => ['required', 'string', 'min:3', 'max:120'],
            'email' => ['required', 'email:rfc', 'max:120', 'unique:users,email'],
            'phone' => ['required', 'string', 'min:9', 'max:30'],
            'program_id' => ['nullable', 'integer', Rule::exists('programs', 'id')->where('is_active', true)],
            'password' => ['required', 'string', 'min:12', 'confirmed'],
            'captcha' => ['required', 'string', 'size:6', 'regex:/^[A-Za-z0-9]{6}$/'],
        ];
    }
}
