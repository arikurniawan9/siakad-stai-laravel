<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can($this->isMethod('post') ? 'users.create' : 'users.update');
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'name' => trim((string) $this->input('name')),
            'username' => $this->filled('username') ? strtolower(trim((string) $this->input('username'))) : null,
            'email' => strtolower(trim((string) $this->input('email'))),
            'is_active' => $this->boolean('is_active'),
            'roles' => array_values(array_unique((array) $this->input('roles', []))),
        ]);
    }

    public function rules(): array
    {
        $model = $this->route('user');
        $id = $model instanceof User ? $model->id : null;
        $password = $this->isMethod('post') ? ['required'] : ['nullable'];

        return [
            'name' => ['required', 'string', 'max:255'],
            'username' => ['nullable', 'string', 'max:100', 'alpha_dash', Rule::unique('users', 'username')->ignore($id)],
            'email' => ['required', 'email:rfc', 'max:255', Rule::unique('users', 'email')->ignore($id)],
            'password' => [...$password, 'string', 'min:12', 'max:200', 'confirmed'],
            'is_active' => ['required', 'boolean'],
            'roles' => ['required', 'array', 'min:1'],
            'roles.*' => ['required', 'string', Rule::exists('roles', 'name')->where('guard_name', 'web')],
            'active_role' => ['required', 'string', Rule::in((array) $this->input('roles', []))],
        ];
    }
}
