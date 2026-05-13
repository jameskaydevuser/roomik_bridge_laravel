<?php

namespace App\Http\Requests\Ebay;

use Illuminate\Foundation\Http\FormRequest;

class GenerateFitmentCsvRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'item_number' => 'required|string',
            'fitment_rows' => 'required|array|min:1',
            'fitment_rows.*.relationship' => 'required|string',
            'fitment_rows.*.relationship_details' => 'required|string',
            'ebay_item_ids' => 'required|array|min:1',
            'ebay_item_ids.*' => 'required|string',
        ];
    }
}
