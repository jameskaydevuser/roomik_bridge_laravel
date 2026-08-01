<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Profile\UpdateProfileRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use OpenApi\Attributes as OA;

class ProfileController extends Controller
{
    #[OA\Put(
        path: "/api/profile",
        summary: "Update the authenticated user's profile details",
        security: [["sanctum" => []]],
        tags: ["Profile"],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType(
                mediaType: "multipart/form-data",
                schema: new OA\Schema(
                    properties: [
                        new OA\Property(property: "name", type: "string", example: "John Updated", description: "User's name"),
                        new OA\Property(property: "email", type: "string", format: "email", example: "john_updated@example.com", description: "User's email"),
                        new OA\Property(property: "password", type: "string", format: "password", example: "newpassword123", description: "User's new password"),
                        new OA\Property(property: "profile_image", type: "string", format: "binary", description: "Profile image file (jpeg, png, jpg, gif, max 2MB)"),
                        new OA\Property(property: "_method", type: "string", example: "PUT", description: "Laravel HTTP method spoofing (send POST request with _method=PUT to upload files)")
                    ]
                )
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: "Profile updated successfully",
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: "id", type: "integer", example: 1),
                        new OA\Property(property: "name", type: "string", example: "John Updated"),
                        new OA\Property(property: "email", type: "string", format: "email", example: "john_updated@example.com"),
                        new OA\Property(property: "profile_image", type: "string", nullable: true, example: "profile_images/xyz.png"),
                        new OA\Property(property: "profile_image_url", type: "string", nullable: true, example: "http://localhost:8000/storage/profile_images/xyz.png"),
                        new OA\Property(property: "created_at", type: "string", format: "date-time", example: "2026-08-01T07:00:00Z"),
                        new OA\Property(property: "updated_at", type: "string", format: "date-time", example: "2026-08-01T07:00:00Z")
                    ]
                )
            ),
            new OA\Response(response: 401, description: "Unauthenticated"),
            new OA\Response(response: 422, description: "Validation error")
        ]
    )]
    public function update(UpdateProfileRequest $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validated();

        if (isset($data['password'])) {
            $data['password'] = Hash::make($data['password']);
        }

        if ($request->hasFile('profile_image')) {
            // Delete old profile image if exists
            if ($user->profile_image) {
                Storage::disk('public')->delete($user->profile_image);
            }
            $path = $request->file('profile_image')->store('profile_images', 'public');
            $data['profile_image'] = $path;
        }

        $user->update($data);

        return response()->json($user);
    }
}
