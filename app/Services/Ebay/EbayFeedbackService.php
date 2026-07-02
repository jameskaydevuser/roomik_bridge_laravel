<?php

namespace App\Services\Ebay;

use App\Models\EbayItemFeedback;
use App\Models\EbayItemFeedbackFetch;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

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

        // CSV Header
        fputcsv($handle, [
            'id',
            'eBay Item ID',
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
}
