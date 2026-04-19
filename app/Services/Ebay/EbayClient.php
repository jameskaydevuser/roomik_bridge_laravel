<?php

namespace App\Services\Ebay;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class EbayClient
{
    protected string $mode;
    protected array $config;

    public function __construct()
    {
        $this->mode = config('ebay.mode');
        $this->config = config("ebay.{$this->mode}");
    }

    /**
     * Send a request to eBay Trading API.
     */
    public function callTradingApi(string $callName, string $xmlBody)
    {
        if (empty($this->config['client_id']) || empty($this->config['auth_token'])) {
            Log::error("eBay Configuration Error: Missing Client ID or Auth Token for mode: {$this->mode}");
            return null;
        }

        $endpoint = config("ebay.api_endpoints.trading.{$this->mode}");
        $siteId = 0; // US Site

        $headers = [
            'X-EBAY-API-COMPATIBILITY-LEVEL' => '1085',
            'X-EBAY-API-DEV-NAME' => $this->config['dev_id'] ?? '',
            'X-EBAY-API-APP-NAME' => $this->config['client_id'],
            'X-EBAY-API-CERT-NAME' => $this->config['client_secret'] ?? '',
            'X-EBAY-API-CALL-NAME' => $callName,
            'X-EBAY-API-SITEID' => $siteId,
            'Content-Type' => 'text/xml',
        ];

        $xmlRequest = "<?xml version=\"1.0\" encoding=\"utf-8\"?>
        <{$callName}Request xmlns=\"urn:ebay:apis:eBLBaseComponents\">
            <RequesterCredentials>
                <eBayAuthToken>{$this->config['auth_token']}</eBayAuthToken>
            </RequesterCredentials>
            {$xmlBody}
        </{$callName}Request>";

        try {
            $response = Http::withHeaders($headers)
                ->withOptions([
                    'timeout' => 30,
                    'verify' => false,
                ])
                ->send('POST', $endpoint, ['body' => $xmlRequest]);

            if ($response->failed()) {
                Log::error("eBay Trading API HTTP Error: {$callName}", [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
                return null;
            }

            $xml = simplexml_load_string($response->body());
            if ($xml === false) {
                Log::error("eBay Trading API XML Parse Error: {$callName}", [
                    'body' => $response->body(),
                ]);
                return null;
            }

            return $xml;
        } catch (\Exception $e) {
            Log::error("eBay Client Exception: {$e->getMessage()}", [
                'call' => $callName,
                'trace' => $e->getTraceAsString(),
            ]);
            return null;
        }
    }
}
