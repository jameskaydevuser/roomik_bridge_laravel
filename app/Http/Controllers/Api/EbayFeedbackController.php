<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Ebay\EbayFeedbackService;
use App\Services\Ebay\EbayListingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use App\Models\EbayItemFeedback;
use OpenApi\Attributes as OA;

class EbayFeedbackController extends Controller
{
    protected EbayFeedbackService $feedbackService;
    protected EbayListingService $listingService;

    public function __construct(EbayFeedbackService $feedbackService, EbayListingService $listingService)
    {
        $this->feedbackService = $feedbackService;
        $this->listingService = $listingService;
    }

    #[OA\Get(
        path: "/api/ebay/listings/{itemId}/feedback",
        summary: "Get feedback for an eBay item",
        security: [["sanctum" => []]],
        tags: ["eBay Feedback"],
        parameters: [
            new OA\Parameter(
                name: "itemId",
                in: "path",
                required: true,
                description: "The eBay Item ID",
                schema: new OA\Schema(type: "string")
            ),
            new OA\Parameter(
                name: "per_page",
                in: "query",
                required: false,
                description: "Number of items per page",
                schema: new OA\Schema(type: "integer", default: 15)
            ),
            new OA\Parameter(
                name: "page",
                in: "query",
                required: false,
                description: "Page number",
                schema: new OA\Schema(type: "integer", default: 1)
            ),
            new OA\Parameter(
                name: "comment_text",
                in: "query",
                required: false,
                description: "Partial search in comment text",
                schema: new OA\Schema(type: "string")
            ),
            new OA\Parameter(
                name: "comment_time_from",
                in: "query",
                required: false,
                description: "Filter feedback date/time from",
                schema: new OA\Schema(type: "string", format: "date-time")
            ),
            new OA\Parameter(
                name: "comment_time_to",
                in: "query",
                required: false,
                description: "Filter feedback date/time to",
                schema: new OA\Schema(type: "string", format: "date-time")
            ),
            new OA\Parameter(
                name: "comment_type",
                in: "query",
                required: false,
                description: "Exact match for comment type",
                schema: new OA\Schema(type: "string", enum: ["Positive", "Neutral", "Negative"])
            ),
            new OA\Parameter(
                name: "role",
                in: "query",
                required: false,
                description: "Exact match for role",
                schema: new OA\Schema(type: "string", enum: ["Buyer", "Seller"])
            )
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: "eBay feedback retrieved successfully",
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: "item_id", type: "string", example: "270646159307"),
                        new OA\Property(property: "sku", type: "string", nullable: true, example: "ABC123"),
                        new OA\Property(
                            property: "feedback",
                            type: "array",
                            items: new OA\Items(
                                properties: [
                                    new OA\Property(property: "id", type: "integer", example: 1),
                                    new OA\Property(property: "ebay_item_id", type: "string", example: "270646159307"),
                                    new OA\Property(property: "feedback_id", type: "string", example: "1029384756"),
                                    new OA\Property(property: "commenting_user", type: "string", example: "buyer_user"),
                                    new OA\Property(property: "commenting_user_score", type: "integer", example: 120),
                                    new OA\Property(property: "comment_text", type: "string", example: "Great seller, fast shipping!"),
                                    new OA\Property(property: "comment_time", type: "string", format: "date-time", example: "2026-07-02T12:00:00Z"),
                                    new OA\Property(property: "comment_type", type: "string", example: "Positive"),
                                    new OA\Property(property: "role", type: "string", example: "Buyer"),
                                    new OA\Property(property: "transaction_id", type: "string", example: "9876543210"),
                                    new OA\Property(property: "created_at", type: "string", format: "date-time", example: "2026-07-02T15:00:00Z"),
                                    new OA\Property(property: "updated_at", type: "string", format: "date-time", example: "2026-07-02T15:00:00Z")
                                ]
                            )
                        ),
                        new OA\Property(
                            property: "pagination",
                            properties: [
                                new OA\Property(property: "current_page", type: "integer", example: 1),
                                new OA\Property(property: "first_page_url", type: "string", example: "http://localhost:8000/api/ebay/listings/270646159307/feedback?page=1"),
                                new OA\Property(property: "from", type: "integer", example: 1),
                                new OA\Property(property: "last_page", type: "integer", example: 1),
                                new OA\Property(property: "last_page_url", type: "string", example: "http://localhost:8000/api/ebay/listings/270646159307/feedback?page=1"),
                                new OA\Property(property: "links", type: "array", items: new OA\Items(type: "object")),
                                new OA\Property(property: "next_page_url", type: "string", nullable: true, example: null),
                                new OA\Property(property: "path", type: "string", example: "http://localhost:8000/api/ebay/listings/270646159307/feedback"),
                                new OA\Property(property: "per_page", type: "integer", example: 15),
                                new OA\Property(property: "prev_page_url", type: "string", nullable: true, example: null),
                                new OA\Property(property: "to", type: "integer", example: 15),
                                new OA\Property(property: "total", type: "integer", example: 1)
                            ]
                        )
                    ]
                )
            ),
            new OA\Response(response: 401, description: "Unauthenticated"),
            new OA\Response(response: 422, description: "Validation error"),
            new OA\Response(response: 500, description: "Server or eBay API error")
        ]
    )]
    public function getFeedback(Request $request, string $itemId): JsonResponse
    {
        Gate::authorize('view', EbayItemFeedback::class);

        $validator = Validator::make(
            array_merge(
                ['itemId' => $itemId],
                $request->only(['per_page', 'comment_text', 'comment_time_from', 'comment_time_to', 'comment_type', 'role'])
            ),
            [
                'itemId' => 'required|string|numeric',
                'per_page' => 'nullable|integer|min:1|max:100',
                'comment_text' => 'nullable|string',
                'comment_time_from' => 'nullable|date',
                'comment_time_to' => 'nullable|date',
                'comment_type' => 'nullable|string|in:Positive,Neutral,Negative',
                'role' => 'nullable|string|in:Buyer,Seller',
            ]
        );

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        $perPage = (int) $request->query('per_page', 15);
        $filters = $request->only(['comment_text', 'comment_time_from', 'comment_time_to', 'comment_type', 'role']);

        $sku = null;
        try {
            $localItem = \App\Models\EbayItem::where('ebay_item_id', $itemId)->whereNotNull('sku')->first();
            if ($localItem) {
                $sku = $localItem->sku;
            } else {
                $sku = Cache::remember("ebay_sku_{$itemId}", now()->addDays(30), function () use ($itemId) {
                    $listing = $this->listingService->getListingByItemId($itemId);
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
            Log::warning("Failed to resolve SKU for item {$itemId} in feedback: " . $e->getMessage());
        }

        try {
            $paginatedFeedback = $this->feedbackService->getFeedback($itemId, $perPage, $filters);
            $paginationData = $paginatedFeedback->toArray();
            unset($paginationData['data']);

            return response()->json([
                'item_id' => $itemId,
                'sku' => $sku,
                'feedback' => $paginatedFeedback->items(),
                'pagination' => $paginationData,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'item_id' => $itemId,
                'sku' => $sku,
                'feedback' => [],
                'pagination' => null,
                'message' => 'Failed to retrieve eBay feedback',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    #[OA\Post(
        path: "/api/ebay/listings/feedback/batch",
        summary: "Get feedback and SKUs for multiple eBay items in a batch",
        security: [["sanctum" => []]],
        tags: ["eBay Feedback"],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ["item_ids"],
                properties: [
                    new OA\Property(
                        property: "item_ids",
                        type: "array",
                        items: new OA\Items(type: "string"),
                        example: ["270646159307", "280572446788"]
                    )
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: "eBay feedback batch retrieved successfully",
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(
                            property: "data",
                            type: "array",
                            items: new OA\Items(
                                properties: [
                                    new OA\Property(property: "item_id", type: "string", example: "270646159307"),
                                    new OA\Property(property: "sku", type: "string", nullable: true, example: "ABC123"),
                                    new OA\Property(property: "feedback", type: "array", items: new OA\Items(type: "object")),
                                    new OA\Property(property: "pagination", type: "object", nullable: true),
                                    new OA\Property(property: "error", type: "string", nullable: true, example: null)
                                ]
                            )
                        )
                    ]
                )
            ),
            new OA\Response(response: 401, description: "Unauthenticated"),
            new OA\Response(response: 422, description: "Validation error")
        ]
    )]
    public function getFeedbackBatch(Request $request): JsonResponse
    {
        Gate::authorize('view', EbayItemFeedback::class);

        $validator = Validator::make($request->all(), [
            'item_ids' => 'required|array',
            'item_ids.*' => 'required|string|numeric',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        $itemIds = $request->input('item_ids');
        $results = [];

        foreach ($itemIds as $itemId) {
            $sku = null;
            $feedbackData = [];
            $paginationData = null;
            $errorMsg = null;

            try {
                try {
                    $localItem = \App\Models\EbayItem::where('ebay_item_id', $itemId)->whereNotNull('sku')->first();
                    if ($localItem) {
                        $sku = $localItem->sku;
                    } else {
                        $sku = Cache::remember("ebay_sku_{$itemId}", now()->addDays(30), function () use ($itemId) {
                            $listing = $this->listingService->getListingByItemId($itemId);
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
                    Log::warning("Failed to resolve SKU for item {$itemId} in batch feedback: " . $e->getMessage());
                    $sku = Cache::get("ebay_sku_{$itemId}");
                    if (!empty($sku)) {
                        \App\Models\EbayItem::saveOrUpdateSku($itemId, $sku);
                    }
                }

                $paginatedFeedback = $this->feedbackService->getFeedback($itemId, 15);
                $paginationData = $paginatedFeedback->toArray();
                unset($paginationData['data']);
                $feedbackData = $paginatedFeedback->items();
            } catch (\Exception $e) {
                $errorMsg = $e->getMessage();
            }

            $results[] = [
                'item_id' => $itemId,
                'sku' => $sku,
                'feedback' => $feedbackData,
                'pagination' => $paginationData,
                'error' => $errorMsg,
            ];
        }

        return response()->json([
            'data' => $results,
        ]);
    }

    #[OA\Get(
        path: "/api/ebay/listings/{itemId}/feedback/export",
        summary: "Export feedback for an eBay item as a CSV file",
        security: [["sanctum" => []]],
        tags: ["eBay Feedback"],
        parameters: [
            new OA\Parameter(
                name: "itemId",
                in: "path",
                required: true,
                description: "The eBay Item ID",
                schema: new OA\Schema(type: "string")
            ),
            new OA\Parameter(
                name: "comment_text",
                in: "query",
                required: false,
                description: "Partial search in comment text",
                schema: new OA\Schema(type: "string")
            ),
            new OA\Parameter(
                name: "comment_time_from",
                in: "query",
                required: false,
                description: "Filter feedback date/time from",
                schema: new OA\Schema(type: "string", format: "date-time")
            ),
            new OA\Parameter(
                name: "comment_time_to",
                in: "query",
                required: false,
                description: "Filter feedback date/time to",
                schema: new OA\Schema(type: "string", format: "date-time")
            ),
            new OA\Parameter(
                name: "comment_type",
                in: "query",
                required: false,
                description: "Exact match for comment type",
                schema: new OA\Schema(type: "string", enum: ["Positive", "Neutral", "Negative"])
            ),
            new OA\Parameter(
                name: "role",
                in: "query",
                required: false,
                description: "Exact match for role",
                schema: new OA\Schema(type: "string", enum: ["Buyer", "Seller"])
            )
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: "CSV file containing eBay feedback exported successfully",
                content: new OA\MediaType(
                    mediaType: "text/csv",
                    schema: new OA\Schema(type: "string", format: "binary")
                )
            ),
            new OA\Response(response: 401, description: "Unauthenticated"),
            new OA\Response(response: 422, description: "Validation error"),
            new OA\Response(response: 500, description: "Server or eBay API error")
        ]
    )]
    public function exportFeedback(Request $request, string $itemId)
    {
        Gate::authorize('export', EbayItemFeedback::class);

        $validator = Validator::make(
            array_merge(
                ['itemId' => $itemId],
                $request->only(['comment_text', 'comment_time_from', 'comment_time_to', 'comment_type', 'role'])
            ),
            [
                'itemId' => 'required|string|numeric',
                'comment_text' => 'nullable|string',
                'comment_time_from' => 'nullable|date',
                'comment_time_to' => 'nullable|date',
                'comment_type' => 'nullable|string|in:Positive,Neutral,Negative',
                'role' => 'nullable|string|in:Buyer,Seller',
            ]
        );

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        $filters = $request->only(['comment_text', 'comment_time_from', 'comment_time_to', 'comment_type', 'role']);

        try {
            $feedbacks = $this->feedbackService->getAllFeedback($itemId, $filters);
            $xlsx = $this->feedbackService->generateXlsx($feedbacks);
            
            return response($xlsx)
                ->header('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
                ->header('Content-Disposition', 'attachment; filename="ebay-feedback-' . $itemId . '.xlsx"');
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to export eBay feedback',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    #[OA\Post(
        path: "/api/ebay/listings/feedback/export/batch",
        summary: "Export feedback for multiple eBay items as a single CSV file",
        security: [["sanctum" => []]],
        tags: ["eBay Feedback"],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ["item_ids"],
                properties: [
                    new OA\Property(
                        property: "item_ids",
                        type: "array",
                        items: new OA\Items(type: "string"),
                        example: ["270646159307", "280572446788"]
                    )
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: "CSV file containing eBay feedback exported successfully",
                content: new OA\MediaType(
                    mediaType: "text/csv",
                    schema: new OA\Schema(type: "string", format: "binary")
                )
            ),
            new OA\Response(response: 401, description: "Unauthenticated"),
            new OA\Response(response: 422, description: "Validation error"),
            new OA\Response(response: 500, description: "Server or eBay API error")
        ]
    )]
    public function exportFeedbackBatch(Request $request)
    {
        Gate::authorize('export', EbayItemFeedback::class);

        $validator = Validator::make($request->all(), [
            'item_ids' => 'required|array',
            'item_ids.*' => 'required|string|numeric',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        $itemIds = $request->input('item_ids');
        $allFeedbacks = collect();

        foreach ($itemIds as $itemId) {
            try {
                $feedbacks = $this->feedbackService->getAllFeedback($itemId);
                $allFeedbacks = $allFeedbacks->concat($feedbacks);
            } catch (\Exception $e) {
                Log::error("Failed to retrieve feedback for item {$itemId} during batch export: " . $e->getMessage());
            }
        }

        try {
            $xlsx = $this->feedbackService->generateXlsx($allFeedbacks);
            
            return response($xlsx)
                ->header('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
                ->header('Content-Disposition', 'attachment; filename="ebay-feedback-batch.xlsx"');
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to export eBay feedback batch',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
