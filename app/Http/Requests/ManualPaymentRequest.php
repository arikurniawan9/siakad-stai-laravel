<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class ManualPaymentRequest extends FormRequest
{
    public function authorize(): bool { return true; }
    public function rules(): array { return ['amount' => ['required', 'numeric', 'min:1'], 'paid_at' => ['required', 'date', 'before_or_equal:now'], 'notes' => ['nullable', 'string', 'max:5000']]; }
}
