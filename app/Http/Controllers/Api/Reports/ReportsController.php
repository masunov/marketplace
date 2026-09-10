<?php

namespace App\Http\Controllers\Api\Reports;

use App\Domain\Services\History\OrderHistoryService;
use App\Http\Controllers\Controller;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReportsController extends Controller
{
    public function index(Request $request, OrderHistoryService $history): JsonResponse
    {
        $validated = $request->validate([
            'from' => ['required', 'date'],
            'to'   => ['required', 'date', 'after_or_equal:from'],
        ]);

        return response()->json([
            'data' => $history->periodTotals(
                CarbonImmutable::parse($validated['from']),
                CarbonImmutable::parse($validated['to']),
            ),
        ]);
    }
}
