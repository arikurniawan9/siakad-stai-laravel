<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class EarlyWarningDecisionRequest extends FormRequest
{
    public function authorize(): bool { return $this->user() !== null; }
    public function rules(): array { return ['status' => ['required', 'in:acknowledged,resolved,open'], 'resolution_notes' => ['nullable', 'string', 'max:5000']]; }
}
