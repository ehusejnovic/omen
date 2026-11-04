<?php

namespace App\Http\Requests;

use App\Enums\GridGrade;
use App\Enums\GridMode;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class GridTradeRequest extends FormRequest
{
    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        $this->merge([
            'symbol' => strtoupper(trim((string) $this->input('symbol'))),
        ]);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'symbol' => ['required', 'string', 'max:32', 'regex:/^[A-Z0-9]+$/'],
            'mode' => ['required', Rule::enum(GridMode::class)],
            'suggested_grade' => ['nullable', Rule::enum(GridGrade::class)],
            'margin' => ['required', 'numeric', 'gt:0', 'max:100000000', 'decimal:0,2'],
            'leverage' => ['required', 'integer', 'between:1,125'],
            'lower_price' => ['nullable', 'numeric', 'gt:0'],
            'upper_price' => ['nullable', 'numeric', 'gt:0', Rule::when($this->filled('lower_price'), 'gt:lower_price')],
            'grid_count' => ['nullable', 'integer', 'between:2,500'],
            'trailing_up' => ['required', 'boolean'],
            'trailing_down' => ['required', 'boolean'],
            'range_horizon_days' => ['nullable', 'integer', Rule::in(config('grid.range_horizons'))],
            'matched_trades' => ['required', 'integer', 'min:0', 'max:1000000'],
            'matched_profit' => ['nullable', 'numeric', 'between:-100000000,100000000', 'decimal:0,2'],
            'pnl' => ['nullable', 'numeric', 'between:-100000000,100000000', 'decimal:0,2'],
            'started_at' => ['required', 'date', 'before_or_equal:now'],
            'ended_at' => ['nullable', 'date', 'after_or_equal:started_at', 'before_or_equal:now'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
