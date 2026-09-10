<?php
declare(strict_types=1);

namespace App\Domain\Services\Reconciliation;

use App\Domain\Entity\Order\Order;
use App\Domain\Entity\Order\OrderItem;
use App\Domain\Entity\Order\OrderProcessingLog;
use App\Domain\Entity\Order\OrderTransaction;
use App\Domain\Entity\PaymentSystem\PaymentCallbackLog;
use App\Models\ExternalVendorKey;

class ReconciliationService
{

    public function __construct(private readonly int $staleMinutes = 15)
    {

    }

    /** @return array<string, mixed> */
    public function report(?int $staleMinutes = null): array
    {
        $minutes = $staleMinutes ?? $this->staleMinutes;
        $stale   = now()->subMinutes($minutes);

        $unsettled      = Order::closedWithUnsettledMoney();
        $paidNotSettled = OrderItem::paidButNotSettled($stale);
        $contentStuck   = OrderItem::contentWithoutDeliveredStatus();
        $rejectedRefund = OrderTransaction::rejectedRefunds();
        $stalledOrders  = Order::stalledBeforeFinalize(200);
        $unpaidOrders   = Order::unpaidLongerThan($stale, 200);
        $mismatched     = PaymentCallbackLog::amountMismatches();
        $unprocessed    = PaymentCallbackLog::unprocessedSummaryOlderThan($stale);

        $anomalies = $unsettled->count()
                     + $paidNotSettled->count()
                     + $contentStuck->count()
                     + $rejectedRefund->count()
                     + $stalledOrders->count()
                     + $mismatched->count()
                     + $unprocessed->count();

        return [
            'generated_at'   => now()->toIso8601String(),
            'stale_minutes'  => $minutes,
            'anomalies'      => $anomalies,
            'money'          => OrderTransaction::balance(),

            'unsettled_closed_orders' => $unsettled,
            'paid_but_not_settled'    => $paidNotSettled,
            'content_without_status'  => $contentStuck,
            'rejected_refunds'        => $rejectedRefund,
            'stalled_before_finalize' => $stalledOrders,
            'amount_mismatch'         => $mismatched,
            'unprocessed_callbacks'   => $unprocessed,

            'observations'   => [
                'unpaid_orders'         => $unpaidOrders,
                'rejected_vendor_codes' => OrderProcessingLog::rejectedCodeSummary($stale),
                'orphaned_vendor_codes' => ExternalVendorKey::orphanedIssued(),
            ],
        ];
    }

}
