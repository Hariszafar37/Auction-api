<?php

namespace App\Http\Requests\Vehicle;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateVehicleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // admin route is already guarded by role:admin middleware
    }

    public function rules(): array
    {
        $vehicleId = $this->route('vehicle')?->id;

        return [
            'vin'             => ['sometimes', 'string', 'size:17', Rule::unique('vehicles', 'vin')->ignore($vehicleId)->whereNull('deleted_at')],
            'year'            => ['sometimes', 'integer', 'min:1900', 'max:' . (date('Y') + 1)],
            'make'            => ['sometimes', 'string', 'max:50'],
            'model'           => ['sometimes', 'string', 'max:50'],
            'trim'            => ['nullable', 'string', 'max:50'],
            'color'           => ['nullable', 'string', 'max:30'],
            'mileage'         => ['nullable', 'integer', 'min:0'],
            'body_type'       => ['sometimes', 'in:car,truck,suv,motorcycle,boat,atv,fleet,other'],
            'transmission'    => ['nullable', 'string', 'max:30'],
            'engine'          => ['nullable', 'string', 'max:50'],
            'fuel_type'       => ['nullable', 'string', 'max:30'],
            'condition_light' => ['sometimes', 'in:green,red,blue'],
            'condition_notes' => ['nullable', 'string', 'max:1000'],
            'has_title'       => ['sometimes', 'boolean'],
            'title_state'     => ['nullable', 'string', 'max:5'],
        ];
    }
}
