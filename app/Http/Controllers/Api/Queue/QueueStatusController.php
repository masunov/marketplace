<?php

namespace App\Http\Controllers\Api\Queue;

use App\Domain\Services\Queue\QueueStatusService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class QueueStatusController extends Controller
{
    public function index(QueueStatusService $queueStatusService): JsonResponse
    {
        return response()->json($queueStatusService->status());
    }
}
