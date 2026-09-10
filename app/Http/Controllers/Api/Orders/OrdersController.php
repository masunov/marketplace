<?php

namespace App\Http\Controllers\Api\Orders;

use App\Domain\Entity\Order\Order;
use App\Domain\Services\Orders\Create\Exceptions\ProductNotFoundException;
use App\Domain\Services\Orders\Create\OrderCreateService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Order\OrderCreateRequest;
use App\Http\Resources\Api\Order\OrderResource;
use App\Domain\Services\History\OrderHistoryService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class OrdersController extends Controller
{
    public function store(OrderCreateRequest $request, OrderCreateService $orderCreateService): JsonResponse
    {
        try {
            $order = $orderCreateService->execute($request->skus());
        } catch (ProductNotFoundException $e) {
            return response()->json(['error' => $e->getMessage()], 404);
        } catch (\Throwable $e) {
            return response()->json(['error' => 'server error'], 500);
        }

        return OrderResource::make($order)
                            ->response()
                            ->setStatusCode(201);
    }

    public function show(string $id, Request $request, OrderHistoryService $history): JsonResponse
    {
        $order = Str::isUuid($id)
            ? Order::findById($id)
            : null;

        if (!$order) {
            return response()->json(['error' => 'Order not found'], 404);
        }

        if ($request->filled('as_of')) {
            $validated = $request->validate(['as_of' => ['date']]);
            $state     = $history->stateAt($order, CarbonImmutable::parse($validated['as_of']));

            return $state
                ? response()->json(['data' => $state])
                : response()->json(['error' => 'Order did not exist at that moment'], 404);
        }

        $order->load(['items.product', 'items.relatedContent', 'transactions']);

        return OrderResource::make($order)->response();
    }
}
