<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Ebay\FetchByItemIdRequest;
use App\Http\Requests\Ebay\FetchBySkuRequest;
use App\Services\Ebay\EbayListingService;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;

class EbayListingController extends Controller
{
    protected EbayListingService $ebayService;

    public function __construct(EbayListingService $ebayService)
    {
        $this->ebayService = $ebayService;
    }

    #[OA\Post(
        path: "/api/ebay/listings/by-sku",
        summary: "Fetch eBay listing data by SKUs",
        security: [["sanctum" => []]],
        tags: ["eBay Listings"],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ["skus"],
                properties: [
                    new OA\Property(property: "skus", type: "array", items: new OA\Items(type: "string"), example: ["ST101"]),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: "Listings fetched successfully",
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: "status", type: "string", example: "success"),
                        new OA\Property(property: "data", type: "array", items: new OA\Items(type: "object")),
                    ]
                )
            ),
            new OA\Response(response: 401, description: "Unauthenticated"),
            new OA\Response(response: 422, description: "Validation error"),
        ]
    )]
    public function bySku(FetchBySkuRequest $request): JsonResponse
    {
        $skus = $request->input('skus');
        $listings = $this->ebayService->getListingsBySkus($skus);

        return response()->json([
            'status' => 'success',
            'data' => $listings,
        ]);
    }

    #[OA\Post(
        path: "/api/ebay/listings/by-item-id",
        summary: "Fetch eBay listing data by Item IDs",
        security: [["sanctum" => []]],
        tags: ["eBay Listings"],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ["item_ids"],
                properties: [
                    new OA\Property(property: "item_ids", type: "array", items: new OA\Items(type: "string"), example: ["270646159307"]),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: "Listings fetched successfully",
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: "status", type: "string", example: "success"),
                        new OA\Property(property: "data", type: "array", items: new OA\Items(type: "object")),
                    ]
                )
            ),
            new OA\Response(response: 401, description: "Unauthenticated"),
            new OA\Response(response: 422, description: "Validation error"),
        ]
    )]
    public function byItemId(FetchByItemIdRequest $request): JsonResponse
    {
        $itemIds = $request->input('item_ids');
        $listings = $this->ebayService->getListingsByItemIds($itemIds);

        return response()->json([
            'status' => 'success',
            'data' => $listings,
        ]);
    }
}
