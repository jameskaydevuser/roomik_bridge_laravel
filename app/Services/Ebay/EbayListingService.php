<?php

namespace App\Services\Ebay;

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
     * Fetch listings by SKUs (one GetItem call per SKU).
     */
    public function getListingsBySkus(array $skus): array
    {
        $results = [];
        foreach ($skus as $sku) {
            $results[] = $this->getItemIdsBySku((string) $sku);
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
            $results[] = [
                'item_id' => $itemId,
                'listing' => $this->getListingByItemId((string) $itemId),
            ];
        }

        return $results;
    }

    /**
     * Fetch a single listing by SKU (one eBay API call).
     */
    public function getItemIdsBySku(string $sku): array
    {
        $itemIds = [];
        $page = 1;
        $entriesPerPage = 200;

        // Active listings: use "now" as EndTimeFrom and up to 120 days ahead as EndTimeTo.
        $endTimeFrom = gmdate('Y-m-d\TH:i:s.000\Z');
        $endTimeTo = gmdate('Y-m-d\TH:i:s.000\Z', strtotime('+119 days'));


        do {
            $escapedSku = htmlspecialchars($sku, ENT_XML1 | ENT_QUOTES, 'UTF-8');

            $xmlBody = "
            <DetailLevel>ReturnAll</DetailLevel>
            <EndTimeFrom>{$endTimeFrom}</EndTimeFrom>
            <EndTimeTo>{$endTimeTo}</EndTimeTo>
            <SKUArray>
                <SKU>{$escapedSku}</SKU>
            </SKUArray>
            <Pagination>
                <EntriesPerPage>{$entriesPerPage}</EntriesPerPage>
                <PageNumber>{$page}</PageNumber>
            </Pagination>
        ";

            $response = $this->client->callTradingApi('GetSellerList', $xmlBody);

            if (!$response) {
                return [
                    'sku' => $sku,
                    'error' => 'eBay API Error: No response from eBay.',
                    'item_ids' => $itemIds,
                ];
            }

            if ((string) $response->Ack === 'Failure') {
                return [
                    'sku' => $sku,
                    'error' => 'eBay API Error: ' . ($response->Errors->LongMessage ?? 'Unknown eBay error'),
                    'item_ids' => $itemIds,
                ];
            }

            if (isset($response->ItemArray->Item)) {
                foreach ($response->ItemArray->Item as $item) {
                    if (isset($item->ItemID)) {
                        $itemIds[] = (string) $item->ItemID;
                    }
                }
            }

            $totalPages = isset($response->PaginationResult->TotalNumberOfPages)
                ? (int) $response->PaginationResult->TotalNumberOfPages
                : 1;

            $page++;
        } while ($page <= $totalPages);

        return [
            'sku' => $sku,
            'item_ids' => array_values(array_unique($itemIds)),
        ];
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
                'error' => 'eBay API Error: No response from eBay. Please check your configuration and credentials.',
            ];
        }

        if ((string) $response->Ack === 'Failure') {
            return [
                'item_id' => $itemId,
                'sku' => $sku,
                'error' => 'eBay API Error: ' . ($response->Errors->LongMessage ?? 'Unknown eBay error'),
            ];
        }

        $normalized = $this->transformer->transform($response);

        if ($sku && empty($normalized['sku'])) {
            $normalized['sku'] = $sku;
        }

        // Save or update EbayItem record
        $resolvedSku = $normalized['sku'] ?? null;
        \App\Models\EbayItem::saveOrUpdateSku($itemId, $resolvedSku);

        return $this->formatter->format($normalized);
    }
}
