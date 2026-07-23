<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

final class AcademicProjectDefenseRequest extends FormRequest
{
    protected function prepareForValidation(): void { $this->merge(['room_id' => filled($this->input('room_id')) ? $this->integer('room_id') : null]); }
    public function authorize(): bool { return true; }
    public function rules(): array
    {
        return ['defense_type' => ['required', Rule::in(['proposal_seminar', 'final_seminar', 'defense'])], 'scheduled_at' => ['required', 'date', 'after:now'], 'ends_at' => ['required', 'date', 'after:scheduled_at'], 'room_id' => ['nullable', 'integer', Rule::exists('rooms', 'id')->whereNull('deleted_at')->where('is_active', true)], 'delivery_mode' => ['required', Rule::in(['onsite', 'online', 'hybrid'])]];
    }
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($this->input('delivery_mode') !== 'online' && ! $this->integer('room_id')) $validator->errors()->add('room_id', 'Ruangan wajib untuk seminar/sidang luring atau hybrid.');
            if ($this->input('delivery_mode') === 'online' && $this->integer('room_id')) $validator->errors()->add('room_id', 'Kegiatan daring tidak memerlukan ruangan.');
        }];
    }
}
