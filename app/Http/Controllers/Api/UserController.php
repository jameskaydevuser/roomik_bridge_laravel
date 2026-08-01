<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\User\StoreUserRequest;
use App\Http\Requests\User\UpdateUserRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Gate;
use OpenApi\Attributes as OA;

class UserController extends Controller
{
    #[OA\Get(
        path: "/api/users",
        summary: "Get a paginated list of users",
        security: [["sanctum" => []]],
        tags: ["Users"],
        parameters: [
            new OA\Parameter(
                name: "per_page",
                in: "query",
                required: false,
                description: "Number of users per page",
                schema: new OA\Schema(type: "integer", default: 15)
            ),
            new OA\Parameter(
                name: "page",
                in: "query",
                required: false,
                description: "Page number",
                schema: new OA\Schema(type: "integer", default: 1)
            )
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: "List of users retrieved successfully",
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: "current_page", type: "integer", example: 1),
                        new OA\Property(
                            property: "data",
                            type: "array",
                            items: new OA\Items(
                                properties: [
                                    new OA\Property(property: "id", type: "integer", example: 1),
                                    new OA\Property(property: "name", type: "string", example: "John Doe"),
                                    new OA\Property(property: "email", type: "string", format: "email", example: "john@example.com"),
                                    new OA\Property(
                                        property: "roles",
                                        type: "array",
                                        items: new OA\Items(
                                            properties: [
                                                new OA\Property(property: "id", type: "integer", example: 1),
                                                new OA\Property(property: "name", type: "string", example: "Admin"),
                                                new OA\Property(property: "guard_name", type: "string", example: "web")
                                            ]
                                        )
                                    ),
                                    new OA\Property(property: "created_at", type: "string", format: "date-time", example: "2026-07-16T08:00:00Z"),
                                    new OA\Property(property: "updated_at", type: "string", format: "date-time", example: "2026-07-16T08:00:00Z")
                                ]
                            )
                        ),
                        new OA\Property(property: "first_page_url", type: "string", example: "http://localhost:8000/api/users?page=1"),
                        new OA\Property(property: "from", type: "integer", example: 1),
                        new OA\Property(property: "last_page", type: "integer", example: 1),
                        new OA\Property(property: "last_page_url", type: "string", example: "http://localhost:8000/api/users?page=1"),
                        new OA\Property(property: "links", type: "array", items: new OA\Items(type: "object")),
                        new OA\Property(property: "next_page_url", type: "string", nullable: true, example: null),
                        new OA\Property(property: "path", type: "string", example: "http://localhost:8000/api/users"),
                        new OA\Property(property: "per_page", type: "integer", example: 15),
                        new OA\Property(property: "prev_page_url", type: "string", nullable: true, example: null),
                        new OA\Property(property: "to", type: "integer", example: 15),
                        new OA\Property(property: "total", type: "integer", example: 1)
                    ]
                )
            ),
            new OA\Response(response: 401, description: "Unauthenticated")
        ]
    )]
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', User::class);

        $perPage = (int) $request->query('per_page', 15);
        $users = User::with('roles')->paginate($perPage);

        return response()->json($users);
    }

    #[OA\Post(
        path: "/api/users",
        summary: "Create a new user",
        security: [["sanctum" => []]],
        tags: ["Users"],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ["name", "email", "password"],
                properties: [
                    new OA\Property(property: "name", type: "string", example: "Jane Doe"),
                    new OA\Property(property: "email", type: "string", format: "email", example: "jane@example.com"),
                    new OA\Property(property: "password", type: "string", format: "password", example: "password123"),
                    new OA\Property(
                        property: "roles",
                        type: "array",
                        items: new OA\Items(type: "string"),
                        example: ["Admin"]
                    )
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 201,
                description: "User created successfully",
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: "id", type: "integer", example: 2),
                        new OA\Property(property: "name", type: "string", example: "Jane Doe"),
                        new OA\Property(property: "email", type: "string", format: "email", example: "jane@example.com"),
                        new OA\Property(
                            property: "roles",
                            type: "array",
                            items: new OA\Items(
                                properties: [
                                    new OA\Property(property: "id", type: "integer", example: 1),
                                    new OA\Property(property: "name", type: "string", example: "Admin"),
                                    new OA\Property(property: "guard_name", type: "string", example: "web")
                                ]
                            )
                        ),
                        new OA\Property(property: "created_at", type: "string", format: "date-time", example: "2026-07-16T08:00:00Z"),
                        new OA\Property(property: "updated_at", type: "string", format: "date-time", example: "2026-07-16T08:00:00Z")
                    ]
                )
            ),
            new OA\Response(response: 401, description: "Unauthenticated"),
            new OA\Response(response: 422, description: "Validation error")
        ]
    )]
    public function store(StoreUserRequest $request): JsonResponse
    {
        Gate::authorize('create', User::class);

        $data = $request->validated();
        $data['password'] = Hash::make($data['password']);

        $user = User::create($data);

        if ($request->has('roles')) {
            $user->syncRoles($request->roles);
        }

        return response()->json($user->load('roles'), 201);
    }

    #[OA\Get(
        path: "/api/users/{id}",
        summary: "Get a specific user's details",
        security: [["sanctum" => []]],
        tags: ["Users"],
        parameters: [
            new OA\Parameter(
                name: "id",
                in: "path",
                required: true,
                description: "The User ID",
                schema: new OA\Schema(type: "integer")
            )
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: "User details retrieved successfully",
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: "id", type: "integer", example: 1),
                        new OA\Property(property: "name", type: "string", example: "John Doe"),
                        new OA\Property(property: "email", type: "string", format: "email", example: "john@example.com"),
                        new OA\Property(
                            property: "roles",
                            type: "array",
                            items: new OA\Items(
                                properties: [
                                    new OA\Property(property: "id", type: "integer", example: 1),
                                    new OA\Property(property: "name", type: "string", example: "Admin"),
                                    new OA\Property(property: "guard_name", type: "string", example: "web")
                                ]
                            )
                        ),
                        new OA\Property(property: "created_at", type: "string", format: "date-time", example: "2026-07-16T08:00:00Z"),
                        new OA\Property(property: "updated_at", type: "string", format: "date-time", example: "2026-07-16T08:00:00Z")
                    ]
                )
            ),
            new OA\Response(response: 401, description: "Unauthenticated"),
            new OA\Response(response: 404, description: "User not found")
        ]
    )]
    public function show(User $user): JsonResponse
    {
        Gate::authorize('view', $user);

        return response()->json($user->load('roles'));
    }

    #[OA\Put(
        path: "/api/users/{id}",
        summary: "Update an existing user's details",
        security: [["sanctum" => []]],
        tags: ["Users"],
        parameters: [
            new OA\Parameter(
                name: "id",
                in: "path",
                required: true,
                description: "The User ID",
                schema: new OA\Schema(type: "integer")
            )
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: "name", type: "string", example: "John Updated"),
                    new OA\Property(property: "email", type: "string", format: "email", example: "john_updated@example.com"),
                    new OA\Property(property: "password", type: "string", format: "password", example: "newpassword123"),
                    new OA\Property(
                        property: "roles",
                        type: "array",
                        items: new OA\Items(type: "string"),
                        example: ["Admin"]
                    )
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: "User updated successfully",
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: "id", type: "integer", example: 1),
                        new OA\Property(property: "name", type: "string", example: "John Updated"),
                        new OA\Property(property: "email", type: "string", format: "email", example: "john_updated@example.com"),
                        new OA\Property(
                            property: "roles",
                            type: "array",
                            items: new OA\Items(
                                properties: [
                                    new OA\Property(property: "id", type: "integer", example: 1),
                                    new OA\Property(property: "name", type: "string", example: "Admin"),
                                    new OA\Property(property: "guard_name", type: "string", example: "web")
                                ]
                            )
                        ),
                        new OA\Property(property: "created_at", type: "string", format: "date-time", example: "2026-07-16T08:00:00Z"),
                        new OA\Property(property: "updated_at", type: "string", format: "date-time", example: "2026-07-16T08:00:00Z")
                    ]
                )
            ),
            new OA\Response(response: 401, description: "Unauthenticated"),
            new OA\Response(response: 404, description: "User not found"),
            new OA\Response(response: 422, description: "Validation error")
        ]
    )]
    public function update(UpdateUserRequest $request, User $user): JsonResponse
    {
        Gate::authorize('update', $user);

        $data = $request->validated();
        if (isset($data['password'])) {
            $data['password'] = Hash::make($data['password']);
        }

        $user->update($data);

        if ($request->has('roles')) {
            $user->syncRoles($request->roles);
        }

        return response()->json($user->load('roles'));
    }

    #[OA\Delete(
        path: "/api/users/{id}",
        summary: "Delete a user",
        security: [["sanctum" => []]],
        tags: ["Users"],
        parameters: [
            new OA\Parameter(
                name: "id",
                in: "path",
                required: true,
                description: "The User ID",
                schema: new OA\Schema(type: "integer")
            )
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: "User deleted successfully",
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: "message", type: "string", example: "User deleted successfully")
                    ]
                )
            ),
            new OA\Response(response: 401, description: "Unauthenticated"),
            new OA\Response(response: 404, description: "User not found")
        ]
    )]
    public function destroy(User $user): JsonResponse
    {
        Gate::authorize('delete', $user);

        if($user->id == auth()->id()) {
            return response()->json([
                'message' => 'You cannot delete your own account'
            ], 403);
        }
        
        $user->delete();

        return response()->json([
            'message' => 'User deleted successfully'
        ]);
    }
}
