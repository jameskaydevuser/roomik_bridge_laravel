<?php

namespace App\Http\Requests\Ebay;

use Illuminate\Foundation\Http\FormRequest;

class FetchByItemIdRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'item_ids' => 'required|array|min:1',
            'item_ids.*' => 'required|string|numeric',
        ];
    }
}
