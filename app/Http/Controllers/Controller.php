<?php

namespace App\Http\Controllers;

use OpenApi\Attributes as OA;

#[OA\Info(
    version: "1.0.0",
    title: "Laravel API Documentation",
    description: "API Documentation for the Authentication module",
    contact: new OA\Contact(email: "admin@example.com")
)]
#[OA\SecurityScheme(
    securityScheme: "sanctum",
    type: "http",
    description: "Login with email and password to get the authentication token",
    name: "Token based Based",
    in: "header",
    scheme: "bearer",
    bearerFormat: "JWT"
)]
abstract class Controller
{
    //
}
