<?php

namespace App\Http\Requests\Settings;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class GridSettingsUpdateRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'total_margin' => ['required', 'numeric', 'min:10', 'max:100000000', 'decimal:0,2'],
            'spacing_multiplier' => ['required', 'numeric', 'between:0.2,1.5', 'decimal:0,2'],
            'allow_trailing' => ['required', 'boolean'],
            'range_horizon_days' => ['required', 'integer', Rule::in(config('grid.range_horizons'))],
        ];
    }
}
