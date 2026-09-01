<?php

namespace App\Http\Controllers\Api\Reconciliation;

use App\Domain\Services\Reconciliation\ReconciliationService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReconciliationController extends Controller
{
    public function index(Request $request, ReconciliationService $reconciliationService): JsonResponse
    {
        $validated = $request->validate([
            'stale_minutes' => ['sometimes', 'integer', 'min:0', 'max:10080'],
        ]);

        $report = $reconciliationService->report($validated['stale_minutes'] ?? null);

        return response()->json($report, $report['anomalies'] > 0 ? 409 : 200);
    }
}
