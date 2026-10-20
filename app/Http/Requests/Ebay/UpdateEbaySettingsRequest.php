<?php

namespace App\Http\Requests\Ebay;

use Illuminate\Foundation\Http\FormRequest;

class UpdateEbaySettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'EBAY_PROD_CLIENT_ID' => 'nullable|string',
            'ebay_prod_client_id' => 'nullable|string',
            'client_id' => 'nullable|string',
            'EBAY_PROD_AUTH_TOKEN' => 'nullable|string',
            'ebay_prod_auth_token' => 'nullable|string',
            'auth_token' => 'nullable|string',
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            if ($this->getClientId() === null && $this->getAuthToken() === null) {
                $validator->errors()->add(
                    'settings',
                    'At least one parameter (EBAY_PROD_CLIENT_ID or EBAY_PROD_AUTH_TOKEN) must be provided.'
                );
            }
        });
    }

    public function getClientId(): ?string
    {
        return $this->input('EBAY_PROD_CLIENT_ID')
            ?? $this->input('ebay_prod_client_id')
            ?? $this->input('client_id');
    }

    public function getAuthToken(): ?string
    {
        return $this->input('EBAY_PROD_AUTH_TOKEN')
            ?? $this->input('ebay_prod_auth_token')
            ?? $this->input('auth_token');
    }
}
