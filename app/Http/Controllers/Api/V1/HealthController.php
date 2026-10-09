<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\InfrastructureHealth;
use Illuminate\Http\JsonResponse;

class HealthController extends Controller
{
    public function live(): JsonResponse
    {
        return response()->json(['data' => ['status' => 'alive']]);
    }

    public function ready(InfrastructureHealth $health): JsonResponse
    {
        $result = $health->check();

        return response()->json(['data' => $result], $result['status'] === 'ready' ? 200 : 503);
    }
}
