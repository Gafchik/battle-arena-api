<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SubmitMoveRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $zones = ['head', 'chest', 'groin', 'legs'];

        return [
            'attack' => ['required', Rule::in($zones)],
            'defend' => ['required', 'array', 'size:2'],
            'defend.*' => ['required', 'distinct', Rule::in($zones)],
        ];
    }
}
