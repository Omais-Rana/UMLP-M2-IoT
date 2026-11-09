<?php

namespace App\Http\Requests;

use App\Models\Calendar;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class PlanRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $minLat = (float) config('mobility.bounding_box.min_lat', 47.45);
        $maxLat = (float) config('mobility.bounding_box.max_lat', 47.72);
        $minLon = (float) config('mobility.bounding_box.min_lon', 6.72);
        $maxLon = (float) config('mobility.bounding_box.max_lon', 6.96);

        return [
            'date' => ['required', 'date_format:Y-m-d'],
            'time' => ['required', 'date_format:H:i'],
            'time_type' => ['required', 'in:depart_at,arrive_by'],
            'objective' => ['nullable', 'in:latest_arrival,sum_arrivals'],

            'destination' => ['required', 'array'],
            'destination.name' => ['required', 'string', 'max:255'],
            'destination.lat' => ['required', 'numeric', "between:{$minLat},{$maxLat}"],
            'destination.lon' => ['required', 'numeric', "between:{$minLon},{$maxLon}"],

            'participants' => ['required', 'array', 'min:1'],
            'participants.*.name' => ['required', 'string', 'max:255'],
            'participants.*.lat' => ['required', 'numeric', "between:{$minLat},{$maxLat}"],
            'participants.*.lon' => ['required', 'numeric', "between:{$minLon},{$maxLon}"],
            'participants.*.has_car' => ['nullable', 'boolean'],
            'participants.*.share_ride' => ['nullable', 'boolean'],
        ];
    }

    /**
     * Configure additional validator checks for calendar validity range and car constraints.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function ($validator) {
            $date = $this->input('date');
            if ($date) {
                $minDate = Calendar::min('start_date') ?? '2026-01-01';
                $maxDate = Calendar::max('end_date') ?? '2027-12-31';

                if ($date < $minDate || $date > $maxDate) {
                    $validator->errors()->add(
                        'date',
                        "The selected date ({$date}) is outside the transit feeds validity window ({$minDate} to {$maxDate})."
                    );
                }
            }

            $participants = $this->input('participants', []);
            foreach ($participants as $index => $p) {
                $hasCar = (bool) ($p['has_car'] ?? false);
                if ($hasCar && ($p['share_ride'] ?? false)) {
                    $validator->errors()->add(
                        "participants.{$index}.share_ride",
                        'Participants with a car cannot select ride sharing.'
                    );
                }
            }
        });
    }

    /**
     * Custom validation messages.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'destination.lat.between' => 'The destination latitude is outside the Belfort-Montbéliard bounding box.',
            'destination.lon.between' => 'The destination longitude is outside the Belfort-Montbéliard bounding box.',
            'participants.*.lat.between' => 'A participant starting point latitude is outside the Belfort-Montbéliard bounding box.',
            'participants.*.lon.between' => 'A participant starting point longitude is outside the Belfort-Montbéliard bounding box.',
        ];
    }
}
