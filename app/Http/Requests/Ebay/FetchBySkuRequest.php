<?php

namespace App\Http\Requests\Ebay;

use Illuminate\Foundation\Http\FormRequest;

class FetchBySkuRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'skus' => 'required|array|min:1',
            'skus.*' => 'required|string',
        ];
    }
}
