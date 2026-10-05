<?php

namespace App\Http\Controllers;

use App\Services\Ops\HealthCheck;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /health with `Authorization: Bearer <HEALTH_TOKEN>` for an uptime monitor. Returns only names and pass/fail
 * (never counts, ages or any content); 503 when a critical check fails. Disabled (404) until HEALTH_TOKEN is set.
 */
class HealthController extends Controller
{
    public function __invoke(Request $request, HealthCheck $health): JsonResponse
    {
        $token = (string) config('moneytalks.health_token');
        if ($token === '') {
            abort(404);
        }
        if (! hash_equals($token, (string) $request->bearerToken())) {
            abort(401);
        }

        $checks = $health->run();
        $healthy = $health->healthy($checks);

        return response()->json([
            'status' => $healthy ? 'ok' : 'failing',
            'checks' => array_map(fn ($c) => ['name' => $c['name'], 'ok' => $c['ok']], $checks),
        ], $healthy ? 200 : 503);
    }
}
