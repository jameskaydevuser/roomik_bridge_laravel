<?php

namespace App\Services\Ebay;

use Illuminate\Support\Facades\Log;

class EbaySkuResolver implements SkuResolverInterface
{
    public function __construct(
        protected EbayClient $client
    ) {}

    /**
     * Resolve a SKU to one or more eBay Item IDs via a single GetItem call.
     */
    public function resolve(string $sku): array
    {
        $sku = trim($sku);
        if ($sku === '') {
            return [];
        }

        $escapedSku = htmlspecialchars($sku, ENT_XML1 | ENT_COMPAT, 'UTF-8');
        $xmlBody = "<SKU>{$escapedSku}</SKU><DetailLevel>ReturnSummary</DetailLevel>";

        $response = $this->client->callTradingApi('GetItem', $xmlBody);

        if (!$response) {
            Log::warning('eBay SKU resolve: no response', ['sku' => $sku]);
            return [];
        }

        if (!in_array((string) $response->Ack, ['Success', 'Warning'], true)) {
            Log::info('eBay SKU resolve: item not found', [
                'sku' => $sku,
                'message' => (string) ($response->Errors->LongMessage ?? $response->Errors->ShortMessage ?? 'Unknown'),
            ]);
            return [];
        }

        $itemId = trim((string) ($response->Item->ItemID ?? ''));
        if ($itemId === '') {
            return [];
        }

        return [$itemId];
    }

    /**
     * Find Item IDs whose Custom label (eBay SKU field) matches the given value.
     * Uses GetItem (direct SKU lookup) plus GetSellerList with SKUArray server-side filter.
     *
     * @return string[]
     */
    public function resolveByCustomLabel(string $sku): array
    {
        $sku = trim($sku);
        if ($sku === '') {
            return [];
        }

        $itemIds = [];

        foreach ($this->resolve($sku) as $itemId) {
            $itemIds[$itemId] = true;
        }

        foreach ($this->fetchItemIdsFromSellerListBySku($sku) as $itemId) {
            $itemIds[$itemId] = true;
        }

        return array_values(array_keys($itemIds));
    }

    /**
     * @return string[]
     */
    private function fetchItemIdsFromSellerListBySku(string $sku): array
    {
        $escapedSku = htmlspecialchars($sku, ENT_XML1 | ENT_COMPAT, 'UTF-8');
        $end = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $start = $end->sub(new \DateInterval('P120D'));

        $startIso = $start->format('Y-m-d\TH:i:s.000\Z');
        $endIso = $end->format('Y-m-d\TH:i:s.000\Z');

        $itemIds = [];
        $page = 1;
        $entriesPerPage = 200;
        $maxPages = 5;

        while ($page <= $maxPages) {
            $xmlBody = "
                <StartTimeFrom>{$startIso}</StartTimeFrom>
                <StartTimeTo>{$endIso}</StartTimeTo>
                <SKUArray>
                    <SKU>{$escapedSku}</SKU>
                </SKUArray>
                <Pagination>
                    <EntriesPerPage>{$entriesPerPage}</EntriesPerPage>
                    <PageNumber>{$page}</PageNumber>
                </Pagination>
                <OutputSelector>ItemArray.Item.ItemID</OutputSelector>
                <OutputSelector>PaginationResult.TotalNumberOfPages</OutputSelector>
            ";

            $response = $this->client->callTradingApi('GetSellerList', $xmlBody);

            if (!$response) {
                Log::warning('eBay Custom label resolve: GetSellerList no response', ['sku' => $sku, 'page' => $page]);
                break;
            }

            if (!in_array((string) $response->Ack, ['Success', 'Warning'], true)) {
                Log::info('eBay Custom label resolve: GetSellerList no matches', [
                    'sku' => $sku,
                    'message' => (string) ($response->Errors->LongMessage ?? $response->Errors->ShortMessage ?? 'Unknown'),
                ]);
                break;
            }

            if (isset($response->ItemArray->Item)) {
                foreach ($response->ItemArray->Item as $item) {
                    $itemId = trim((string) ($item->ItemID ?? ''));
                    if ($itemId !== '') {
                        $itemIds[$itemId] = true;
                    }
                }
            }

            $totalPages = (int) ($response->PaginationResult->TotalNumberOfPages ?? 1);
            if ($page >= $totalPages) {
                break;
            }

            $page++;
        }

        return array_values(array_keys($itemIds));
    }
}
