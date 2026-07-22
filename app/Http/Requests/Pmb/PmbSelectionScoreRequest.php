<?php

namespace App\Http\Requests\Pmb;

use App\Models\PmbSelection;
use Illuminate\Foundation\Http\FormRequest;

final class PmbSelectionScoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        $selection = $this->route('selection');

        return $selection instanceof PmbSelection && (bool) $this->user()?->can('update', $selection);
    }

    public function rules(): array
    {
        return [
            'scores' => ['required', 'array', 'min:1'],
            'scores.*' => ['required', 'numeric', 'min:0', 'max:10000'],
        ];
    }
}
