<?php

use App\Http\Controllers\Api\Orders\OrdersController;
use App\Http\Controllers\Api\PaymentSystems\PsMirController;
use App\Http\Controllers\Api\Queue\QueueStatusController;
use App\Http\Controllers\Api\Reconciliation\ReconciliationController;
use App\Http\Controllers\Api\Reports\ReportsController;
use Illuminate\Support\Facades\Route;


Route::prefix('orders')->group(function () {
    Route::post('/', [OrdersController::class, 'store']);
    Route::get('{id}', [OrdersController::class, 'show']);
});


Route::prefix('payment_systems')->group(function () {
    Route::prefix('ps_mir')->group(function () {
        Route::post('callback', [PsMirController::class, 'callback']);
    });
});


Route::get('reconciliation', [ReconciliationController::class, 'index']);

Route::get('queue/status', [QueueStatusController::class, 'index']);

Route::get('reports', [ReportsController::class, 'index']);
