<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ClientClusterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'bbox' => [
                'nullable',
                'string',
                function ($attribute, $value, $fail) {
                    $parts = explode(',', $value);
                    if (count($parts) !== 4) {
                        return $fail('The bbox parameter must consist of 4 comma-separated numbers: west,south,east,north.');
                    }

                    foreach ($parts as $part) {
                        if (!is_numeric(trim($part))) {
                            return $fail('All bbox coordinate values must be numeric.');
                        }
                    }

                    $west = (float) trim($parts[0]);
                    $south = (float) trim($parts[1]);
                    $east = (float) trim($parts[2]);
                    $north = (float) trim($parts[3]);

                    if ($west < -180 || $west > 180 || $east < -180 || $east > 180) {
                        return $fail('Longitude values in bbox must be between -180 and 180 degrees.');
                    }

                    if ($south < -90 || $south > 90 || $north < -90 || $north > 90) {
                        return $fail('Latitude values in bbox must be between -90 and 90 degrees.');
                    }

                    if ($south > $north) {
                        return $fail('The south latitude cannot be greater than north latitude.');
                    }
                },
            ],
            'zoom' => 'nullable|integer|min:0|max:22',
            'from' => 'nullable|date_format:Y-m-d',
            'to' => 'nullable|date_format:Y-m-d|after_or_equal:from',
            'assignedUserId' => 'nullable|integer',
            'status' => 'nullable|string|max:50',
            'limit' => 'nullable|integer|min:1|max:2000',
        ];
    }

    /**
     * Parse and return parsed bounding box array or null.
     *
     * @return array{west: float, south: float, east: float, north: float}|null
     */
    public function getParsedBbox(): ?array
    {
        $raw = $this->query('bbox');
        if (!$raw) {
            return null;
        }

        $parts = explode(',', $raw);
        if (count($parts) !== 4) {
            return null;
        }

        return [
            'west' => (float) trim($parts[0]),
            'south' => (float) trim($parts[1]),
            'east' => (float) trim($parts[2]),
            'north' => (float) trim($parts[3]),
        ];
    }
}
