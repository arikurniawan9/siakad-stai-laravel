<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class LmsForumTopicRequest extends FormRequest
{
    public function authorize(): bool { return true; }
    public function rules(): array { return ['title' => ['required', 'string', 'max:180'], 'content' => ['required', 'string', 'max:20000']]; }
}
