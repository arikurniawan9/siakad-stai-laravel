<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'identifier' => ['required', 'string', 'max:120'],
            'password' => ['required', 'string', 'max:200'],
            'remember' => ['sometimes', 'boolean'],
            'captcha' => ['required', 'string', 'size:6', 'regex:/^[A-Za-z0-9]{6}$/'],
        ];
    }
}
