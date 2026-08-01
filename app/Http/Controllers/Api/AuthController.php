<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Validator;
use App\Models\User;

class AuthController extends Controller
{
    #[OA\Post(
        path: "/api/login",
        summary: "Login user and create token",
        tags: ["Authentication"],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ["email", "password"],
                properties: [
                    new OA\Property(property: "email", type: "string", format: "email", example: "admin@example.com"),
                    new OA\Property(property: "password", type: "string", format: "password", example: "password123"),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: "Login successful",
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: "message", type: "string", example: "Login successful"),
                        new OA\Property(property: "access_token", type: "string", example: "1|XYZ..."),
                        new OA\Property(property: "token_type", type: "string", example: "Bearer"),
                        new OA\Property(property: "name", type: "string", example: "Admin User"),
                        new OA\Property(property: "role", type: "string", nullable: true, example: "Admin"),
                        new OA\Property(property: "profile_image", type: "string", nullable: true, example: "profile_images/xyz.png"),
                        new OA\Property(property: "profile_image_url", type: "string", nullable: true, example: "http://localhost:8000/storage/profile_images/xyz.png"),
                        new OA\Property(
                            property: "permissions",
                            type: "array",
                            items: new OA\Items(type: "string"),
                            example: ["lookup_ebay_listing", "get_ebay_feedback"]
                        )
                    ]
                )
            ),
            new OA\Response(response: 401, description: "Invalid login credentials"),
            new OA\Response(response: 422, description: "Validation error"),
        ]
    )]
    public function login(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email',
            'password' => 'required',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        if (!Auth::attempt($request->only('email', 'password'))) {
            return response()->json([
                'message' => 'Invalid login credentials',
            ], 401);
        }

        $user = User::where('email', $request->email)->firstOrFail();
        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'message' => 'Login successful',
            'access_token' => $token,
            'token_type' => 'Bearer',
            'name' => $user->name,
            'role' => $user->getRoleNames()->first(),
            'profile_image' => $user->profile_image,
            'profile_image_url' => $user->profile_image_url,
            'permissions' => $user->getAllPermissions()->pluck('name'),
        ], 200);
    }

    #[OA\Post(
        path: "/api/logout",
        summary: "Logout user (Revoke the token)",
        security: [["sanctum" => []]],
        tags: ["Authentication"],
        responses: [
            new OA\Response(
                response: 200,
                description: "Logged out successfully",
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: "message", type: "string", example: "Logged out successfully"),
                    ]
                )
            ),
            new OA\Response(response: 401, description: "Unauthenticated"),
        ]
    )]
    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'message' => 'Logged out successfully'
        ], 200);
    }

    #[OA\Post(
        path: "/api/forgot-password",
        summary: "Send password reset link",
        tags: ["Authentication"],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ["email"],
                properties: [
                    new OA\Property(property: "email", type: "string", format: "email", example: "admin@example.com"),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: "Password reset link sent",
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: "message", type: "string", example: "passwords.sent"),
                    ]
                )
            ),
            new OA\Response(response: 400, description: "Invalid email or user not found"),
            new OA\Response(response: 422, description: "Validation error"),
        ]
    )]
    public function forgotPassword(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email|exists:users,email',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        $status = Password::sendResetLink(
            $request->only('email')
        );

        if ($status === Password::RESET_LINK_SENT) {
            return response()->json([
                'message' => __($status)
            ], 200);
        }

        return response()->json([
            'message' => __($status)
        ], 400);
    }

    #[OA\Get(
        path: "/api/me",
        summary: "Get authenticated user profile details",
        security: [["sanctum" => []]],
        tags: ["Authentication"],
        responses: [
            new OA\Response(
                response: 200,
                description: "Success",
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: "name", type: "string", example: "Admin User"),
                        new OA\Property(property: "role", type: "string", nullable: true, example: "Admin"),
                        new OA\Property(property: "profile_image", type: "string", nullable: true, example: "profile_images/xyz.png"),
                        new OA\Property(property: "profile_image_url", type: "string", nullable: true, example: "http://localhost:8000/storage/profile_images/xyz.png"),
                        new OA\Property(
                            property: "permissions",
                            type: "array",
                            items: new OA\Items(type: "string"),
                            example: ["lookup_ebay_listing", "get_ebay_feedback"]
                        )
                    ]
                )
            ),
            new OA\Response(response: 401, description: "Unauthenticated")
        ]
    )]
    public function me(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'name' => $user->name,
            'role' => $user->getRoleNames()->first(),
            'profile_image' => $user->profile_image,
            'profile_image_url' => $user->profile_image_url,
            'permissions' => $user->getAllPermissions()->pluck('name'),
        ], 200);
    }
}
