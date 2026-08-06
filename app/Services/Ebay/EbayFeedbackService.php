<?php

namespace App\Services\Ebay;

use App\Models\EbayItemFeedback;
use App\Models\EbayItemFeedbackFetch;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

class EbayFeedbackService
{
    protected EbayClient $client;

    public function __construct(EbayClient $client)
    {
        $this->client = $client;
    }

    /**
     * Get feedback for an eBay item, fetching from eBay if cached data is stale or missing.
     *
     * @param string $itemId
     * @param int $perPage
     * @return LengthAwarePaginator
     * @throws \Exception
     */
    public function getFeedback(string $itemId, int $perPage = 15, array $filters = []): LengthAwarePaginator
    {
        $fetchLog = EbayItemFeedbackFetch::find($itemId);

        // Check if we need to fetch fresh data (data is missing or older than 1 hour)
        $shouldFetch = !$fetchLog || Carbon::parse($fetchLog->last_fetched_at)->addHour()->isPast();

        if ($shouldFetch) {
            try {
                $this->fetchAndStoreFeedback($itemId);
            } catch (\Exception $e) {
                if (EbayItemFeedback::where('ebay_item_id', $itemId)->count() === 0) {
                    throw $e;
                }
            }
        }

        $query = EbayItemFeedback::where('ebay_item_id', $itemId);

        if (!empty($filters['comment_text'])) {
            $query->where('comment_text', 'like', '%' . $filters['comment_text'] . '%');
        }

        if (!empty($filters['comment_time_from'])) {
            $query->where('comment_time', '>=', Carbon::parse($filters['comment_time_from']));
        }

        if (!empty($filters['comment_time_to'])) {
            $query->where('comment_time', '<=', Carbon::parse($filters['comment_time_to']));
        }

        if (!empty($filters['comment_type'])) {
            $query->where('comment_type', $filters['comment_type']);
        }

        if (!empty($filters['role'])) {
            $query->where('role', $filters['role']);
        }

        return $query->orderBy('comment_time', 'desc')
            ->paginate($perPage);
    }

    /**
     * Get all feedback for an eBay item, fetching from eBay if cached data is stale or missing.
     *
     * @param string $itemId
     * @param array $filters
     * @return \Illuminate\Database\Eloquent\Collection
     * @throws \Exception
     */
    public function getAllFeedback(string $itemId, array $filters = [])
    {
        $fetchLog = EbayItemFeedbackFetch::find($itemId);

        // Check if we need to fetch fresh data (data is missing or older than 1 hour)
        $shouldFetch = !$fetchLog || Carbon::parse($fetchLog->last_fetched_at)->addHour()->isPast();

        if ($shouldFetch) {
            try {
                $this->fetchAndStoreFeedback($itemId);
            } catch (\Exception $e) {
                if (EbayItemFeedback::where('ebay_item_id', $itemId)->count() === 0) {
                    throw $e;
                }
            }
        }

        $query = EbayItemFeedback::where('ebay_item_id', $itemId);

        if (!empty($filters['comment_text'])) {
            $query->where('comment_text', 'like', '%' . $filters['comment_text'] . '%');
        }

        if (!empty($filters['comment_time_from'])) {
            $query->where('comment_time', '>=', Carbon::parse($filters['comment_time_from']));
        }

        if (!empty($filters['comment_time_to'])) {
            $query->where('comment_time', '<=', Carbon::parse($filters['comment_time_to']));
        }

        if (!empty($filters['comment_type'])) {
            $query->where('comment_type', $filters['comment_type']);
        }

        if (!empty($filters['role'])) {
            $query->where('role', $filters['role']);
        }

        return $query->orderBy('comment_time', 'desc')
            ->get();
    }

    /**
     * Generate CSV content for a collection of feedback records.
     *
     * @param iterable $feedbacks
     * @return string
     */
    public function generateCsv(iterable $feedbacks): string
    {
        $handle = fopen('php://memory', 'r+');

        // Resolve SKUs for the item IDs
        $itemIds = [];
        foreach ($feedbacks as $feedback) {
            $itemIds[] = $feedback->ebay_item_id;
        }
        $itemIds = array_unique($itemIds);

        $skuMap = [];
        $listingService = app(\App\Services\Ebay\EbayListingService::class);
        foreach ($itemIds as $itemId) {
            $sku = null;
            try {
                $localItem = \App\Models\EbayItem::where('ebay_item_id', $itemId)->whereNotNull('sku')->first();
                if ($localItem) {
                    $sku = $localItem->sku;
                } else {
                    $sku = Cache::remember("ebay_sku_{$itemId}", now()->addDays(30), function () use ($listingService, $itemId) {
                        $listing = $listingService->getListingByItemId($itemId);
                        if (isset($listing['error'])) {
                            throw new \Exception($listing['error']);
                        }
                        return $listing['sku'] ?? null;
                    });
                    
                    if (!empty($sku)) {
                        \App\Models\EbayItem::saveOrUpdateSku($itemId, $sku);
                    }
                }
            } catch (\Exception $e) {
                Log::warning("Failed to resolve SKU for item {$itemId} in CSV generation: " . $e->getMessage());
            }
            $skuMap[$itemId] = $sku;
        }

        // CSV Header
        fputcsv($handle, [
            'id',
            'eBay Item ID',
            'SKU',
            'Feedback ID',
            'Commenting User',
            'Commenting User Score',
            'Comment Text',
            'Comment Time',
            'Comment Type',
            'Role',
            'Transaction ID'
        ]);

        foreach ($feedbacks as $feedback) {
            fputcsv($handle, [
                $feedback->id,
                $feedback->ebay_item_id,
                $skuMap[$feedback->ebay_item_id] ?? '',
                $feedback->feedback_id,
                $feedback->commenting_user,
                $feedback->commenting_user_score,
                $feedback->comment_text,
                $feedback->comment_time ? $feedback->comment_time->toIso8601String() : '',
                $feedback->comment_type,
                $feedback->role,
                $feedback->transaction_id,
            ]);
        }

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }

    /**
     * Fetch feedback from eBay and upsert into the database.
     *
     * @param string $itemId
     * @return void
     * @throws \Exception
     */
    protected function fetchAndStoreFeedback(string $itemId): void
    {
        $xmlBody = "
            <ItemID>{$itemId}</ItemID>
            <DetailLevel>ReturnAll</DetailLevel>
        ";

        $response = $this->client->callTradingApi('GetFeedback', $xmlBody);

        if ($response && (string) $response->Ack !== 'Failure') {
            $records = [];
            if (isset($response->FeedbackDetailArray->FeedbackDetail)) {
                foreach ($response->FeedbackDetailArray->FeedbackDetail as $detail) {
                    $records[] = [
                        'ebay_item_id' => $itemId,
                        'feedback_id' => (string) $detail->FeedbackID,
                        'commenting_user' => (string) $detail->CommentingUser,
                        'commenting_user_score' => isset($detail->CommentingUserScore) ? (int) $detail->CommentingUserScore : null,
                        'comment_text' => isset($detail->CommentText) ? (string) $detail->CommentText : null,
                        'comment_time' => isset($detail->CommentTime)
                            ? Carbon::parse((string) $detail->CommentTime)->toDateTimeString()
                            : null,
                        'comment_type' => (string) $detail->CommentType,
                        'role' => (string) $detail->Role,
                        'transaction_id' => isset($detail->TransactionID) ? (string) $detail->TransactionID : null,
                        'created_at' => now()->toDateTimeString(),
                        'updated_at' => now()->toDateTimeString(),
                    ];
                }
            }

            if (!empty($records)) {
                EbayItemFeedback::upsert($records, ['feedback_id'], [
                    'commenting_user',
                    'commenting_user_score',
                    'comment_text',
                    'comment_time',
                    'comment_type',
                    'role',
                    'transaction_id',
                    'updated_at',
                ]);
            }

            EbayItemFeedbackFetch::updateOrCreate(
                ['ebay_item_id' => $itemId],
                ['last_fetched_at' => now()]
            );
        } else {
            $errorMsg = $response ? ($response->Errors->LongMessage ?? 'Unknown eBay error') : 'No response from eBay';
            Log::error("eBay GetFeedback API Error for item {$itemId}: {$errorMsg}");

            // If we have no cached data, propagate the error as an exception
            if (EbayItemFeedback::where('ebay_item_id', $itemId)->count() === 0) {
                throw new \Exception("Failed to fetch feedback from eBay: {$errorMsg}");
            }
        }
    }

    /**
     * Generate an Excel .xlsx binary file content for feedbacks collection.
     */
    public function generateXlsx(iterable $feedbacks): string
    {
        // 1. Resolve SKUs (same as generateCsv)
        $itemIds = [];
        foreach ($feedbacks as $feedback) {
            $itemIds[] = $feedback->ebay_item_id;
        }
        $itemIds = array_unique($itemIds);

        $skuMap = [];
        $listingService = app(\App\Services\Ebay\EbayListingService::class);
        foreach ($itemIds as $itemId) {
            $sku = null;
            try {
                $localItem = \App\Models\EbayItem::where('ebay_item_id', $itemId)->whereNotNull('sku')->first();
                if ($localItem) {
                    $sku = $localItem->sku;
                } else {
                    $sku = Cache::remember("ebay_sku_{$itemId}", now()->addDays(30), function () use ($listingService, $itemId) {
                        $listing = $listingService->getListingByItemId($itemId);
                        if (isset($listing['error'])) {
                            throw new \Exception($listing['error']);
                        }
                        return $listing['sku'] ?? null;
                    });
                    
                    if (!empty($sku)) {
                        \App\Models\EbayItem::saveOrUpdateSku($itemId, $sku);
                    }
                }
            } catch (\Exception $e) {
                Log::warning("Failed to resolve SKU for item {$itemId} in XLSX generation: " . $e->getMessage());
            }
            $skuMap[$itemId] = $sku;
        }

        // 2. Create Sheet1 XML content
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n";
        $xml .= '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">' . "\n";
        $xml .= '    <sheetData>' . "\n";

        $headers = [
            'id', 'eBay Item ID', 'SKU', 'Feedback ID', 'Commenting User',
            'Commenting User Score', 'Comment Text', 'Comment Time', 'Comment Type', 'Role', 'Transaction ID'
        ];

        // Header Row (r=1)
        $xml .= '        <row r="1">' . "\n";
        foreach ($headers as $colIdx => $header) {
            $cellRef = $this->getExcelCellRef($colIdx, 1);
            $escaped = htmlspecialchars($header, ENT_XML1 | ENT_COMPAT, 'UTF-8');
            $xml .= '            <c r="' . $cellRef . '" t="inlineStr"><is><t>' . $escaped . '</t></is></c>' . "\n";
        }
        $xml .= '        </row>' . "\n";

        // Data Rows
        $rowIdx = 2;
        foreach ($feedbacks as $feedback) {
            $rowValues = [
                $feedback->id,
                $feedback->ebay_item_id,
                $skuMap[$feedback->ebay_item_id] ?? '',
                $feedback->feedback_id,
                $feedback->commenting_user,
                $feedback->commenting_user_score,
                $feedback->comment_text,
                $feedback->comment_time ? $feedback->comment_time->toIso8601String() : '',
                $feedback->comment_type,
                $feedback->role,
                $feedback->transaction_id,
            ];

            $xml .= '        <row r="' . $rowIdx . '">' . "\n";
            foreach ($rowValues as $colIdx => $val) {
                $cellRef = $this->getExcelCellRef($colIdx, $rowIdx);
                $escaped = htmlspecialchars((string)$val, ENT_XML1 | ENT_COMPAT, 'UTF-8');
                $xml .= '            <c r="' . $cellRef . '" t="inlineStr"><is><t>' . $escaped . '</t></is></c>' . "\n";
            }
            $xml .= '        </row>' . "\n";
            $rowIdx++;
        }

        $xml .= '    </sheetData>' . "\n";
        $xml .= '</worksheet>';

        // 3. Package as ZIP (XLSX) in memory
        $tempFile = tempnam(sys_get_temp_dir(), 'xlsx');
        $zip = new \ZipArchive();
        if ($zip->open($tempFile, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) === true) {
            $zip->addFromString('[Content_Types].xml', $this->getContentTypesXml());
            $zip->addFromString('_rels/.rels', $this->getRelsXml());
            $zip->addFromString('xl/workbook.xml', $this->getWorkbookXml());
            $zip->addFromString('xl/_rels/workbook.xml.rels', $this->getWorkbookRelsXml());
            $zip->addFromString('xl/worksheets/sheet1.xml', $xml);
            $zip->close();
        }

        $xlsxContent = file_get_contents($tempFile);
        unlink($tempFile);

        return $xlsxContent;
    }

    private function getExcelCellRef(int $colIdx, int $rowIdx): string
    {
        $letters = '';
        $temp = $colIdx;
        while ($temp >= 0) {
            $letters = chr(($temp % 26) + 65) . $letters;
            $temp = intval($temp / 26) - 1;
        }
        return $letters . $rowIdx;
    }

    private function getContentTypesXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
    <Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>
    <Default Extension="xml" ContentType="application/xml"/>
    <Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>
    <Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>
</Types>';
    }

    private function getRelsXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
    <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>
</Relationships>';
    }

    private function getWorkbookXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
    <sheets>
        <sheet name="Feedback" sheetId="1" r:id="rId1"/>
    </sheets>
</workbook>';
    }

    private function getWorkbookRelsXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
    <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>
</Relationships>';
    }
}
