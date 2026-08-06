<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use App\Services\Ebay\EbayClient;

class EbayService
{
    protected string $clientId;
    protected string $clientSecret;
    protected string $refreshToken;
    protected string $env;
    protected string $marketplaceId;
    protected string $baseUrl;
    protected string $tokenUrl;

    public function __construct()
    {
        $this->clientId = (string)config('services.ebay.client_id', '');
        $this->clientSecret = (string)config('services.ebay.client_secret', '');
        $this->refreshToken = (string)config('services.ebay.refresh_token', '');
        $this->env = (string)config('services.ebay.env');
        if (empty($this->env)) {
            $this->env = (string)config('ebay.mode', 'sandbox');
        }
        $this->marketplaceId = (string)config('services.ebay.marketplace_id', 'EBAY_US');

        if ($this->env === 'production') {
            $this->baseUrl = 'https://api.ebay.com';
            $this->tokenUrl = 'https://api.ebay.com/identity/v1/oauth2/token';
        } else {
            $this->baseUrl = 'https://api.sandbox.ebay.com';
            $this->tokenUrl = 'https://api.sandbox.ebay.com/identity/v1/oauth2/token';
        }
    }

    /**
     * Get a valid eBay OAuth access token, refreshing if necessary.
     */
    public function getAccessToken(): ?string
    {
        // 1. Prioritize the configured token from config/ebay.php (which is working in the active environment)
        $mode = config('ebay.mode', 'sandbox');
        $configToken = (string)config("ebay.{$mode}.auth_token");

        if (!empty($configToken)) {
            return $configToken;
        }

        // 2. Fallback to cache / refresh token flow if client credentials are provided
        $cacheKey = 'ebay_access_token_' . $this->env;
        $token = Cache::get($cacheKey);

        if ($token) {
            return $token;
        }

        if (empty($this->clientId) || empty($this->clientSecret) || empty($this->refreshToken)) {
            Log::error('eBay Service: Missing credentials for refresh token flow.');
            return null;
        }

        try {
            $response = Http::asForm()
                ->withOptions([
                    'verify' => false,
                ])
                ->withHeaders([
                    'Authorization' => 'Basic ' . base64_encode($this->clientId . ':' . $this->clientSecret),
                ])
                ->post($this->tokenUrl, [
                    'grant_type' => 'refresh_token',
                    'refresh_token' => $this->refreshToken,
                    'scope' => 'https://api.ebay.com/oauth/api_scope/sell.inventory',
                ]);

            if ($response->failed()) {
                Log::error('eBay Token Refresh Failed', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
                return null;
            }

            $data = $response->json();
            $accessToken = $data['access_token'] ?? null;
            $expiresIn = $data['expires_in'] ?? 7200;

            if ($accessToken) {
                // Cache the access token. Subtract 60 seconds to prevent expiration timing issues.
                Cache::put($cacheKey, $accessToken, $expiresIn - 60);
                return $accessToken;
            }
        } catch (\Exception $e) {
            Log::error('eBay Token Refresh Exception', [
                'message' => $e->getMessage(),
            ]);
        }

        return null;
    }

    /**
     * Fetch eBay listing details associated with a given SKU (single GetItem call).
     */
    public function getListingsBySku(string $sku): array
    {
        try {
            $ebayClient = app(EbayClient::class);
            $sku = trim($sku);

            $escapedSku = htmlspecialchars($sku, ENT_XML1 | ENT_COMPAT, 'UTF-8');
            $xmlBody = "<SKU>{$escapedSku}</SKU><DetailLevel>ReturnAll</DetailLevel>";

            $response = $ebayClient->callTradingApi('GetItem', $xmlBody);

            if (!$response) {
                return [
                    'success' => false,
                    'message' => 'eBay API Error: No response from eBay. Please check your configuration and credentials.',
                    'status_code' => 500,
                ];
            }

            $ack = (string) ($response->Ack ?? '');

            if (!in_array($ack, ['Success', 'Warning'], true)) {
                $message = 'eBay API Error';
                $ebayError = [];
                $errorCode = 0;

                if (isset($response->Errors)) {
                    $errors = $response->Errors;
                    if (!isset($errors[0])) {
                        $errors = [$errors];
                    }
                    foreach ($errors as $err) {
                        $msg = (string) ($err->LongMessage ?? $err->ShortMessage ?? 'Unknown eBay error');
                        $message = $msg;
                        $errorCode = (int) ($err->ErrorCode ?? 0);

                        $ebayError[] = [
                            'errorId' => $errorCode,
                            'domain' => 'TRADING',
                            'category' => (string) ($err->ErrorClassification ?? 'REQUEST'),
                            'message' => (string) ($err->ShortMessage ?? $msg),
                            'longMessage' => (string) ($err->LongMessage ?? $msg),
                        ];
                    }
                }

                // Item not found — return empty result set (not an application error).
                if ($errorCode === 17) {
                    return [
                        'success' => true,
                        'sku' => $sku,
                        'count' => 0,
                        'items' => [],
                    ];
                }

                return [
                    'success' => false,
                    'message' => $message,
                    'ebay_error' => $ebayError,
                    'status_code' => 400,
                ];
            }

            $item = $response->Item ?? null;
            if (!$item) {
                return [
                    'success' => true,
                    'sku' => $sku,
                    'count' => 0,
                    'items' => [],
                ];
            }

            $itemId = (string) ($item->ItemID ?? '');
            $listingStatus = strtoupper((string) ($item->SellingStatus->ListingStatus ?? ''));
            $site = (string) ($item->Site ?? 'US');

            $marketplaceId = 'EBAY_' . strtoupper($site);
            if ($site === 'eBayMotors') {
                $marketplaceId = 'EBAY_MOTORS';
            }

            $listingType = (string) ($item->ListingType ?? '');
            $format = 'UNKNOWN';
            if ($listingType === 'Chinese') {
                $format = 'AUCTION';
            } elseif ($listingType === 'FixedPriceItem') {
                $format = 'FIXED_PRICE';
            }

            // Save or update EbayItem record
            \App\Models\EbayItem::saveOrUpdateSku($itemId, $sku);

            return [
                'success' => true,
                'sku' => $sku,
                'count' => 1,
                'items' => [
                    [
                        'sku' => $sku,
                        'offer_id' => $itemId,
                        'listing_id' => $itemId,
                        'listing_status' => $listingStatus,
                        'marketplace_id' => $marketplaceId,
                        'format' => $format,
                    ],
                ],
            ];
        } catch (\Exception $e) {
            Log::error('eBay API GetItem by SKU Exception', [
                'sku' => $sku,
                'message' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => 'An unexpected error occurred: ' . $e->getMessage(),
                'status_code' => 500,
            ];
        }
    }
}
