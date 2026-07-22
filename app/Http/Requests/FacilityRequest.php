<?php

namespace App\Http\Requests;

use App\Models\Building;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class FacilityRequest extends FormRequest
{
    public function authorize(): bool
    {
        $resource = (string) $this->route('resource');
        $action = $this->isMethod('post') ? 'create' : 'update';

        return in_array($resource, ['buildings', 'rooms'], true)
            && (bool) $this->user()?->can($resource.'.'.$action);
    }

    protected function prepareForValidation(): void
    {
        $data = [
            'code' => strtoupper(trim((string) $this->input('code'))),
            'is_active' => $this->boolean('is_active'),
        ];

        if ($this->route('resource') === 'rooms') {
            $facilities = $this->input('facilities', []);
            if (is_string($facilities)) {
                $facilities = array_values(array_filter(array_map('trim', explode(',', $facilities))));
            }
            $data['facilities'] = $facilities;
        }

        $this->merge($data);
    }

    public function rules(): array
    {
        $resource = (string) $this->route('resource');
        $id = $this->route('id');

        return match ($resource) {
            'buildings' => [
                'campus_id' => [
                    'required',
                    'integer',
                    Rule::exists('campuses', 'id')->whereNull('deleted_at')->where('is_active', true),
                ],
                'name' => ['required', 'string', 'max:120'],
                'code' => [
                    'required',
                    'string',
                    'max:30',
                    'alpha_dash',
                    Rule::unique('buildings', 'code')
                        ->where(fn ($query) => $query->where('campus_id', $this->integer('campus_id')))
                        ->ignore($id),
                ],
                'floor_count' => ['required', 'integer', 'min:1', 'max:100'],
                'description' => ['nullable', 'string', 'max:1000'],
                'is_active' => ['required', 'boolean'],
            ],
            'rooms' => [
                'building_id' => [
                    'required',
                    'integer',
                    Rule::exists('buildings', 'id')->whereNull('deleted_at')->where('is_active', true),
                ],
                'name' => ['required', 'string', 'max:120'],
                'code' => [
                    'required',
                    'string',
                    'max:30',
                    'alpha_dash',
                    Rule::unique('rooms', 'code')
                        ->where(fn ($query) => $query->where('building_id', $this->integer('building_id')))
                        ->ignore($id),
                ],
                'floor' => ['required', 'integer', 'min:1', 'max:100'],
                'type' => ['required', Rule::in(['Kelas', 'Laboratorium', 'Aula', 'Kantor', 'Perpustakaan', 'Lainnya'])],
                'capacity' => ['required', 'integer', 'min:1', 'max:10000'],
                'facilities' => ['nullable', 'array', 'max:30'],
                'facilities.*' => ['string', 'max:100', 'distinct:ignore_case'],
                'is_active' => ['required', 'boolean'],
            ],
            default => [],
        };
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            if ($this->route('resource') === 'rooms') {
                $building = Building::query()->find($this->integer('building_id'));
                if ($building && $this->integer('floor') > $building->floor_count) {
                    $validator->errors()->add('floor', 'Lantai ruangan tidak boleh melebihi jumlah lantai gedung.');
                }
            }

            if ($this->route('resource') === 'buildings' && $this->route('id')) {
                $highestRoomFloor = (int) Building::query()
                    ->find($this->route('id'))
                    ?->rooms()
                    ->max('floor');
                if ($highestRoomFloor > $this->integer('floor_count')) {
                    $validator->errors()->add('floor_count', "Jumlah lantai tidak boleh kurang dari lantai ruangan tertinggi ({$highestRoomFloor}).");
                }
            }
        }];
    }
}
