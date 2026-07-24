<?php

namespace App\Http\Controllers;

use OpenApi\Attributes as OA;

#[OA\Info(
    version: '1.0.0',
    description: 'API documentation and testing interface for PrepYourMeal.',
    title: 'PrepYourMeal API'
)]
#[OA\SecurityScheme(
    securityScheme: 'bearerAuth',
    type: 'http',
    scheme: 'bearer'
)]
#[OA\Server(
    url: '/api',
    description: 'API Server'
)]
abstract class Controller
{
    //
}
