<?php
namespace App\Http\Requests;
use Illuminate\Foundation\Http\FormRequest;
final class GuidanceAvailabilityRequest extends FormRequest { public function authorize(): bool { return $this->user() !== null; } public function rules(): array { return ['weekday' => ['required', 'integer', 'between:1,7'], 'starts_at' => ['required', 'date_format:H:i'], 'ends_at' => ['required', 'date_format:H:i', 'after:starts_at'], 'mode' => ['required', 'in:online,onsite,phone']]; } }
