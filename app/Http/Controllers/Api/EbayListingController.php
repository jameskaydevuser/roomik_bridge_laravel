<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Ebay\FetchByItemIdRequest;
use App\Http\Requests\Ebay\FetchBySkuRequest;
use App\Services\Ebay\EbayListingService;
use App\Services\EbayService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class EbayListingController extends Controller
{
    protected EbayListingService $ebayService;
    protected \App\Services\Ebay\EbayFitmentCsvService $fitmentService;
    protected EbayService $ebayInventoryService;

    public function __construct(
        EbayListingService $ebayService,
        \App\Services\Ebay\EbayFitmentCsvService $fitmentService,
        EbayService $ebayInventoryService
    ) {
        $this->ebayService = $ebayService;
        $this->fitmentService = $fitmentService;
        $this->ebayInventoryService = $ebayInventoryService;
    }

    #[OA\Get(
        path: "/api/ebay/listings/by-sku",
        summary: "Get eBay listings for a SKU using Inventory API",
        security: [["sanctum" => []]],
        tags: ["eBay Listings"],
        parameters: [
            new OA\Parameter(
                name: "sku",
                in: "query",
                required: true,
                description: "The SKU value to retrieve listings for",
                schema: new OA\Schema(type: "string")
            )
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: "eBay listings retrieved successfully",
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: "success", type: "boolean", example: true),
                        new OA\Property(property: "sku", type: "string", example: "ABC123"),
                        new OA\Property(property: "count", type: "integer", example: 1),
                        new OA\Property(
                            property: "items",
                            type: "array",
                            items: new OA\Items(
                                properties: [
                                    new OA\Property(property: "sku", type: "string", example: "ABC123"),
                                    new OA\Property(property: "offer_id", type: "string", example: "123456789"),
                                    new OA\Property(property: "listing_id", type: "string", example: "987654321"),
                                    new OA\Property(property: "listing_status", type: "string", example: "ACTIVE"),
                                    new OA\Property(property: "marketplace_id", type: "string", example: "EBAY_US"),
                                    new OA\Property(property: "format", type: "string", example: "FIXED_PRICE")
                                ]
                            )
                        )
                    ]
                )
            ),
            new OA\Response(response: 401, description: "Unauthenticated"),
            new OA\Response(response: 422, description: "Validation error"),
            new OA\Response(response: 500, description: "eBay API Error")
        ]
    )]
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
    public function bySku(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'sku' => 'required|string',
        ]);

        $listings = $this->ebayService->getItemIdsBySku($validated['sku']);

        return response()->json([
            'status' => 'success',
            'data' => $listings,
        ]);
    }

    public function byItemId(FetchByItemIdRequest $request): JsonResponse
    {
        $itemIds = $request->input('item_ids');
        $listings = $this->ebayService->getListingsByItemIds($itemIds);

        return response()->json([
            'status' => 'success',
            'data' => $listings,
        ]);
    }

    #[OA\Post(
        path: "/api/generate-ebay-fitment-csv",
        summary: "Generate eBay Motors fitment CSV file",
        security: [["sanctum" => []]],
        tags: ["eBay Listings"],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ["item_number", "fitment_rows", "ebay_item_ids"],
                properties: [
                    new OA\Property(property: "item_number", type: "string", example: "123456789012"),
                    new OA\Property(
                        property: "fitment_rows",
                        type: "array",
                        items: new OA\Items(
                            properties: [
                                new OA\Property(property: "relationship", type: "string", example: "Compatibility"),
                                new OA\Property(property: "relationship_details", type: "string", example: "Make=Yamaha|Model=Big Bear 350|Year=1998|Submodel=YFM350FW 4x4")
                            ]
                        )
                    ),
                    new OA\Property(property: "ebay_item_ids", type: "array", items: new OA\Items(type: "string"), example: ["270646159307"])
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: "CSV file generated successfully",
                content: new OA\MediaType(
                    mediaType: "text/csv",
                    schema: new OA\Schema(type: "string", format: "binary")
                )
            ),
            new OA\Response(response: 401, description: "Unauthenticated"),
            new OA\Response(response: 422, description: "Validation error"),
        ]
    )]
    public function generateFitmentCsv(\App\Http\Requests\Ebay\GenerateFitmentCsvRequest $request)
    {
        $csv = $this->fitmentService->generate(
            $request->input('item_number'),
            $request->input('fitment_rows'),
            $request->input('ebay_item_ids')
        );

        return response($csv)
            ->header('Content-Type', 'text/csv')
            ->header('Content-Disposition', 'attachment; filename="ebay_fitment.csv"');
    }
}
