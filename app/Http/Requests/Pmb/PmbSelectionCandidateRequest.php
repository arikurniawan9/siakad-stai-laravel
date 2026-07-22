<?php

namespace App\Http\Requests\Pmb;

use App\Models\PmbSelection;
use Illuminate\Foundation\Http\FormRequest;

final class PmbSelectionCandidateRequest extends FormRequest
{
    public function authorize(): bool
    {
        $selection = $this->route('selection');

        return $selection instanceof PmbSelection && (bool) $this->user()?->can('update', $selection);
    }

    public function rules(): array
    {
        return ['pmb_application_id' => ['required', 'integer', 'exists:pmb_applications,id']];
    }
}
