<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Role\StoreRoleRequest;
use App\Http\Requests\Role\UpdateRoleRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\Models\Role;
use OpenApi\Attributes as OA;

class RoleController extends Controller
{
    #[OA\Get(
        path: "/api/roles/all",
        summary: "Get all roles without pagination formatted as value/title",
        security: [["sanctum" => []]],
        tags: ["Roles"],
        responses: [
            new OA\Response(
                response: 200,
                description: "List of all roles retrieved successfully",
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(
                            property: "roles",
                            type: "array",
                            items: new OA\Items(
                                properties: [
                                    new OA\Property(property: "value", type: "integer", example: 1),
                                    new OA\Property(property: "title", type: "string", example: "Admin")
                                ]
                            )
                        )
                    ]
                )
            ),
            new OA\Response(response: 401, description: "Unauthenticated")
        ]
    )]
    public function all(): JsonResponse
    {
        $roles = Role::query()->orderBy('id')->get();

        $roles->transform(function ($role) {
            return [
                'value' => $role->id,
                'title' => $role->name,
            ];
        });

        return response()->json([
            'roles' => $roles,
        ]);
    }

    #[OA\Get(
        path: "/api/roles",
        summary: "Get a paginated list of roles with their permissions",
        security: [["sanctum" => []]],
        tags: ["Roles"],
        parameters: [
            new OA\Parameter(
                name: "per_page",
                in: "query",
                required: false,
                description: "Number of roles per page",
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
                description: "List of roles retrieved successfully",
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: "current_page", type: "integer", example: 1),
                        new OA\Property(
                            property: "data",
                            type: "array",
                            items: new OA\Items(
                                properties: [
                                    new OA\Property(property: "id", type: "integer", example: 1),
                                    new OA\Property(property: "name", type: "string", example: "Admin"),
                                    new OA\Property(property: "guard_name", type: "string", example: "web"),
                                    new OA\Property(
                                        property: "permissions",
                                        type: "array",
                                        items: new OA\Items(
                                            properties: [
                                                new OA\Property(property: "id", type: "integer", example: 1),
                                                new OA\Property(property: "name", type: "string", example: "lookup_ebay_listing"),
                                                new OA\Property(property: "guard_name", type: "string", example: "web")
                                            ]
                                        )
                                    ),
                                    new OA\Property(property: "created_at", type: "string", format: "date-time", example: "2026-08-01T07:00:00Z"),
                                    new OA\Property(property: "updated_at", type: "string", format: "date-time", example: "2026-08-01T07:00:00Z")
                                ]
                            )
                        ),
                        new OA\Property(property: "first_page_url", type: "string", example: "http://localhost:8000/api/roles?page=1"),
                        new OA\Property(property: "from", type: "integer", example: 1),
                        new OA\Property(property: "last_page", type: "integer", example: 1),
                        new OA\Property(property: "last_page_url", type: "string", example: "http://localhost:8000/api/roles?page=1"),
                        new OA\Property(property: "links", type: "array", items: new OA\Items(type: "object")),
                        new OA\Property(property: "next_page_url", type: "string", nullable: true, example: null),
                        new OA\Property(property: "path", type: "string", example: "http://localhost:8000/api/roles"),
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
        Gate::authorize('viewAny', Role::class);

        $perPage = (int) $request->query('per_page', 15);
        $roles = Role::with('permissions')->paginate($perPage);

        return response()->json($roles);
    }

    #[OA\Post(
        path: "/api/roles",
        summary: "Create a new role",
        security: [["sanctum" => []]],
        tags: ["Roles"],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ["name"],
                properties: [
                    new OA\Property(property: "name", type: "string", example: "Manager"),
                    new OA\Property(
                        property: "permissions",
                        type: "array",
                        items: new OA\Items(type: "string"),
                        example: ["lookup_ebay_listing", "get_ebay_feedback"]
                    )
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 201,
                description: "Role created successfully",
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: "id", type: "integer", example: 2),
                        new OA\Property(property: "name", type: "string", example: "Manager"),
                        new OA\Property(property: "guard_name", type: "string", example: "web"),
                        new OA\Property(
                            property: "permissions",
                            type: "array",
                            items: new OA\Items(
                                properties: [
                                    new OA\Property(property: "id", type: "integer", example: 1),
                                    new OA\Property(property: "name", type: "string", example: "lookup_ebay_listing")
                                ]
                            )
                        ),
                        new OA\Property(property: "created_at", type: "string", format: "date-time", example: "2026-08-01T07:00:00Z"),
                        new OA\Property(property: "updated_at", type: "string", format: "date-time", example: "2026-08-01T07:00:00Z")
                    ]
                )
            ),
            new OA\Response(response: 401, description: "Unauthenticated"),
            new OA\Response(response: 422, description: "Validation error")
        ]
    )]
    public function store(StoreRoleRequest $request): JsonResponse
    {
        Gate::authorize('create', Role::class);

        $role = Role::create([
            'name' => $request->name,
            'guard_name' => 'web'
        ]);

        if ($request->has('permissions')) {
            $role->syncPermissions($request->permissions);
        }

        return response()->json($role->load('permissions'), 201);
    }

    #[OA\Get(
        path: "/api/roles/{id}",
        summary: "Get details for a specific role",
        security: [["sanctum" => []]],
        tags: ["Roles"],
        parameters: [
            new OA\Parameter(
                name: "id",
                in: "path",
                required: true,
                description: "The Role ID",
                schema: new OA\Schema(type: "integer")
            )
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: "Role details retrieved successfully",
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: "id", type: "integer", example: 1),
                        new OA\Property(property: "name", type: "string", example: "Admin"),
                        new OA\Property(property: "guard_name", type: "string", example: "web"),
                        new OA\Property(
                            property: "permissions",
                            type: "array",
                            items: new OA\Items(
                                properties: [
                                    new OA\Property(property: "id", type: "integer", example: 1),
                                    new OA\Property(property: "name", type: "string", example: "lookup_ebay_listing")
                                ]
                            )
                        ),
                        new OA\Property(property: "created_at", type: "string", format: "date-time", example: "2026-08-01T07:00:00Z"),
                        new OA\Property(property: "updated_at", type: "string", format: "date-time", example: "2026-08-01T07:00:00Z")
                    ]
                )
            ),
            new OA\Response(response: 401, description: "Unauthenticated"),
            new OA\Response(response: 404, description: "Role not found")
        ]
    )]
    public function show(Role $role): JsonResponse
    {
        Gate::authorize('view', $role);

        return response()->json($role->load('permissions'));
    }

    #[OA\Put(
        path: "/api/roles/{id}",
        summary: "Update an existing role and its permissions",
        security: [["sanctum" => []]],
        tags: ["Roles"],
        parameters: [
            new OA\Parameter(
                name: "id",
                in: "path",
                required: true,
                description: "The Role ID",
                schema: new OA\Schema(type: "integer")
            )
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: "name", type: "string", example: "Admin Updated"),
                    new OA\Property(
                        property: "permissions",
                        type: "array",
                        items: new OA\Items(type: "string"),
                        example: ["lookup_ebay_listing", "export_ebay_listing"]
                    )
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: "Role updated successfully",
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: "id", type: "integer", example: 1),
                        new OA\Property(property: "name", type: "string", example: "Admin Updated"),
                        new OA\Property(property: "guard_name", type: "string", example: "web"),
                        new OA\Property(
                            property: "permissions",
                            type: "array",
                            items: new OA\Items(
                                properties: [
                                    new OA\Property(property: "id", type: "integer", example: 1),
                                    new OA\Property(property: "name", type: "string", example: "lookup_ebay_listing")
                                ]
                            )
                        ),
                        new OA\Property(property: "created_at", type: "string", format: "date-time", example: "2026-08-01T07:00:00Z"),
                        new OA\Property(property: "updated_at", type: "string", format: "date-time", example: "2026-08-01T07:00:00Z")
                    ]
                )
            ),
            new OA\Response(response: 401, description: "Unauthenticated"),
            new OA\Response(response: 404, description: "Role not found"),
            new OA\Response(response: 422, description: "Validation error")
        ]
    )]
    public function update(UpdateRoleRequest $request, Role $role): JsonResponse
    {
        Gate::authorize('update', $role);

        if ($request->has('name')) {
            $role->name = $request->name;
            $role->save();
        }

        if ($request->has('permissions')) {
            $role->syncPermissions($request->permissions);
        }

        return response()->json($role->load('permissions'));
    }

    #[OA\Delete(
        path: "/api/roles/{id}",
        summary: "Delete a role",
        security: [["sanctum" => []]],
        tags: ["Roles"],
        parameters: [
            new OA\Parameter(
                name: "id",
                in: "path",
                required: true,
                description: "The Role ID",
                schema: new OA\Schema(type: "integer")
            )
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: "Role deleted successfully",
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: "message", type: "string", example: "Role deleted successfully")
                    ]
                )
            ),
            new OA\Response(response: 401, description: "Unauthenticated"),
            new OA\Response(response: 404, description: "Role not found")
        ]
    )]
    public function destroy(Role $role): JsonResponse
    {
        Gate::authorize('delete', $role);

        $role->delete();

        return response()->json([
            'message' => 'Role deleted successfully'
        ]);
    }
}
