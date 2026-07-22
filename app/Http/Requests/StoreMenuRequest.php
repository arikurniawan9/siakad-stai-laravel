<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreMenuRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('menus.update');
    }

    public function rules(): array
    {
        return [
            'key' => [
                'required',
                'string',
                'max:80',
                'regex:/^[a-z0-9._-]+$/',
                Rule::unique('menus', 'key')->ignore($this->route('menu')),
            ],
            'label' => ['required', 'string', 'max:100'],
            'href' => ['nullable', 'string', 'max:180'],
            'icon' => ['nullable', 'string', 'max:50'],
            'permission' => ['nullable', 'string', 'max:100'],
            'parent_id' => ['nullable', 'integer', 'exists:menus,id'],
            'sort_order' => ['required', 'integer', 'min:0', 'max:9999'],
            'is_active' => ['sometimes', 'boolean'],
            'roles' => ['array'],
            'roles.*' => ['string', 'exists:roles,name'],
        ];
    }
}
