<?php

namespace App\Services\Ebay;

use Illuminate\Support\Facades\Log;

class EbayListingService
{
    protected EbayClient $client;
    protected SkuResolverInterface $skuResolver;
    protected EbayListingTransformer $transformer;
    protected EbayRowsFormatter $formatter;

    public function __construct(
        EbayClient $client, 
        SkuResolverInterface $skuResolver,
        EbayListingTransformer $transformer,
        EbayRowsFormatter $formatter
    ) {
        $this->client = $client;
        $this->skuResolver = $skuResolver;
        $this->transformer = $transformer;
        $this->formatter = $formatter;
    }

    /**
     * Fetch listings by SKUs.
     */
    public function getListingsBySkus(array $skus): array
    {
        $results = [];
        foreach ($skus as $sku) {
            $itemIds = $this->skuResolver->resolve($sku);
            
            if (empty($itemIds)) {
                $results[] = [
                    'sku' => $sku,
                    'error' => "Could not resolve SKU to any eBay Item ID",
                ];
                continue;
            }

            foreach ($itemIds as $itemId) {
                $results[] = $this->getListingByItemId($itemId, $sku);
            }
        }

        return $results;
    }

    /**
     * Fetch listings by Item IDs.
     */
    public function getListingsByItemIds(array $itemIds): array
    {
        $results = [];
        foreach ($itemIds as $itemId) {
            $results[] = $this->getListingByItemId($itemId);
        }
        return $results;
    }

    /**
     * Fetch a single listing by Item ID.
     */
    public function getListingByItemId(string $itemId, ?string $sku = null): array
    {
        $xmlBody = "<ItemID>{$itemId}</ItemID>
                    <DetailLevel>ReturnAll</DetailLevel>
                    <IncludeItemCompatibilityList>true</IncludeItemCompatibilityList>
                    <IncludeItemSpecifics>true</IncludeItemSpecifics>";

        $response = $this->client->callTradingApi('GetItem', $xmlBody);

        if (!$response) {
            return [
                'item_id' => $itemId,
                'sku' => $sku,
                'error' => "eBay API Error: No response from eBay. Please check your configuration and credentials.",
            ];
        }

        if ((string)$response->Ack === 'Failure') {
            return [
                'item_id' => $itemId,
                'sku' => $sku,
                'error' => "eBay API Error: " . ($response->Errors->LongMessage ?? 'Unknown eBay error'),
            ];
        }

        $normalized = $this->transformer->transform($response);
        
        // If SKU was provided via resolver, ensure it's in the response
        if ($sku && empty($normalized['sku'])) {
            $normalized['sku'] = $sku;
        }

        return $this->formatter->format($normalized);
    }
}
