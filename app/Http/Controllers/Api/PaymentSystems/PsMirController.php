<?php

namespace App\Http\Controllers\Api\PaymentSystems;

use App\Domain\Entity\PaymentSystem\PaymentSystemEnum;
use App\Domain\Services\PaymentSystems\PsMir\Dto\PaymentCallbackDto;
use App\Domain\Services\PaymentSystems\PsMir\ProcessPaymentCallbackService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\PaymentSystems\PsMirCallbackRequest;
use Illuminate\Http\JsonResponse;

class PsMirController extends Controller
{
    public function callback(
        PsMirCallbackRequest $request,
        ProcessPaymentCallbackService $processPaymentCallbackService
    ): JsonResponse
    {
        $processPaymentCallbackService->execute(
            PaymentCallbackDto::fromArray(PaymentSystemEnum::PS_MIR, $request->validated())
        );

        return response()->json(['status' => 'accepted']);
    }
}
