<?php

namespace App\Http\Requests;

use App\Enums\GridStrategy;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class GridScanRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'strategy' => ['sometimes', Rule::enum(GridStrategy::class)],
        ];
    }

    /**
     * The strategy to scan, swing when none was given.
     */
    public function strategy(): GridStrategy
    {
        return $this->enum('strategy', GridStrategy::class) ?? GridStrategy::Swing;
    }
}
