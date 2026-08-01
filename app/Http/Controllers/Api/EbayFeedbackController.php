<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Ebay\EbayFeedbackService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use App\Models\EbayItemFeedback;
use OpenApi\Attributes as OA;

class EbayFeedbackController extends Controller
{
    protected EbayFeedbackService $feedbackService;

    public function __construct(EbayFeedbackService $feedbackService)
    {
        $this->feedbackService = $feedbackService;
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
                        new OA\Property(property: "current_page", type: "integer", example: 1),
                        new OA\Property(
                            property: "data",
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

        try {
            $paginatedFeedback = $this->feedbackService->getFeedback($itemId, $perPage, $filters);
            return response()->json($paginatedFeedback);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to retrieve eBay feedback',
                'error' => $e->getMessage()
            ], 500);
        }
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
            $csv = $this->feedbackService->generateCsv($feedbacks);
            
            return response($csv)
                ->header('Content-Type', 'text/csv')
                ->header('Content-Disposition', 'attachment; filename="ebay-feedback-' . $itemId . '.csv"');
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to export eBay feedback',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
