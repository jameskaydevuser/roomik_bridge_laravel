<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Ebay\UpdateEbaySettingsRequest;
use App\Models\Setting;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use OpenApi\Attributes as OA;

class EbaySettingController extends Controller
{
    #[OA\Get(
        path: "/api/ebay/settings",
        summary: "Get current eBay production configuration parameters",
        security: [["sanctum" => []]],
        tags: ["eBay Settings"],
        responses: [
            new OA\Response(
                response: 200,
                description: "eBay settings retrieved successfully",
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: "success", type: "boolean", example: true),
                        new OA\Property(
                            property: "data",
                            type: "object",
                            properties: [
                                new OA\Property(property: "EBAY_PROD_CLIENT_ID", type: "string", example: "CaltricI-Caltric-PRD-146241164-c7b797e8"),
                                new OA\Property(property: "EBAY_PROD_AUTH_TOKEN", type: "string", example: "v^1.1#i^1#p^3#..."),
                            ]
                        )
                    ]
                )
            ),
            new OA\Response(response: 401, description: "Unauthenticated"),
            new OA\Response(response: 403, description: "Forbidden - Requires 'settings' permission")
        ]
    )]
    public function getSettings(): JsonResponse
    {
        Gate::authorize('settings', Setting::class);

        $clientId = Setting::get('EBAY_PROD_CLIENT_ID', config('ebay.production.client_id'));
        $authToken = Setting::get('EBAY_PROD_AUTH_TOKEN', config('ebay.production.auth_token'));

        return response()->json([
            'success' => true,
            'data' => [
                'EBAY_PROD_CLIENT_ID' => $clientId,
                'EBAY_PROD_AUTH_TOKEN' => $authToken,
            ],
        ]);
    }

    #[OA\Post(
        path: "/api/ebay/settings",
        summary: "Update eBay production configuration parameters in the database",
        security: [["sanctum" => []]],
        tags: ["eBay Settings"],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(
                        property: "EBAY_PROD_CLIENT_ID",
                        type: "string",
                        example: "CaltricI-Caltric-PRD-146241164-c7b797e8",
                        description: "eBay Production Client ID (also accepts ebay_prod_client_id or client_id)"
                    ),
                    new OA\Property(
                        property: "EBAY_PROD_AUTH_TOKEN",
                        type: "string",
                        example: "v^1.1#i^1#p^3#f^0#I^3#r^1#t^Ul4xMF8y...",
                        description: "eBay Production Auth Token (also accepts ebay_prod_auth_token or auth_token)"
                    ),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: "eBay settings updated successfully",
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: "success", type: "boolean", example: true),
                        new OA\Property(property: "message", type: "string", example: "eBay parameters updated successfully"),
                        new OA\Property(
                            property: "data",
                            type: "object",
                            properties: [
                                new OA\Property(property: "EBAY_PROD_CLIENT_ID", type: "string", example: "CaltricI-Caltric-PRD-146241164-c7b797e8"),
                                new OA\Property(property: "EBAY_PROD_AUTH_TOKEN", type: "string", example: "v^1.1#i^1#p^3#..."),
                            ]
                        )
                    ]
                )
            ),
            new OA\Response(response: 401, description: "Unauthenticated"),
            new OA\Response(response: 403, description: "Forbidden - Requires 'update_settings' permission"),
            new OA\Response(response: 422, description: "Validation error")
        ]
    )]
    #[OA\Put(
        path: "/api/ebay/settings",
        summary: "Update eBay production configuration parameters in the database (PUT alias)",
        security: [["sanctum" => []]],
        tags: ["eBay Settings"],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(
                        property: "EBAY_PROD_CLIENT_ID",
                        type: "string",
                        example: "CaltricI-Caltric-PRD-146241164-c7b797e8",
                        description: "eBay Production Client ID"
                    ),
                    new OA\Property(
                        property: "EBAY_PROD_AUTH_TOKEN",
                        type: "string",
                        example: "v^1.1#i^1#p^3#f^0#I^3#r^1#t^Ul4xMF8y...",
                        description: "eBay Production Auth Token"
                    ),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: "eBay settings updated successfully",
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: "success", type: "boolean", example: true),
                        new OA\Property(property: "message", type: "string", example: "eBay parameters updated successfully"),
                        new OA\Property(
                            property: "data",
                            type: "object",
                            properties: [
                                new OA\Property(property: "EBAY_PROD_CLIENT_ID", type: "string", example: "CaltricI-Caltric-PRD-146241164-c7b797e8"),
                                new OA\Property(property: "EBAY_PROD_AUTH_TOKEN", type: "string", example: "v^1.1#i^1#p^3#..."),
                            ]
                        )
                    ]
                )
            ),
            new OA\Response(response: 401, description: "Unauthenticated"),
            new OA\Response(response: 403, description: "Forbidden - Requires 'update_settings' permission"),
            new OA\Response(response: 422, description: "Validation error")
        ]
    )]
    public function updateSettings(UpdateEbaySettingsRequest $request): JsonResponse
    {
        Gate::authorize('update_settings', Setting::class);

        $clientId = $request->getClientId();
        $authToken = $request->getAuthToken();

        if ($clientId !== null) {
            Setting::set('EBAY_PROD_CLIENT_ID', $clientId);
        }

        if ($authToken !== null) {
            Setting::set('EBAY_PROD_AUTH_TOKEN', $authToken);
        }

        $currentClientId = Setting::get('EBAY_PROD_CLIENT_ID', config('ebay.production.client_id'));
        $currentAuthToken = Setting::get('EBAY_PROD_AUTH_TOKEN', config('ebay.production.auth_token'));

        return response()->json([
            'success' => true,
            'message' => 'eBay parameters updated successfully',
            'data' => [
                'EBAY_PROD_CLIENT_ID' => $currentClientId,
                'EBAY_PROD_AUTH_TOKEN' => $currentAuthToken,
            ],
        ]);
    }
}
