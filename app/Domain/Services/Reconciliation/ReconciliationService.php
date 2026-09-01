<?php
declare(strict_types=1);

namespace App\Domain\Services\Reconciliation;

use App\Domain\Entity\Order\Order;
use App\Domain\Entity\PaymentSystem\PaymentCallbackLog;
use App\Domain\Entity\ProductVendor\VendorKey;

class ReconciliationService
{

    public function __construct(private readonly int $staleMinutes = 15)
    {

    }

    /**
     * @return array<string, mixed>
     */
    public function report(?int $staleMinutes = null): array
    {
        $minutes = $staleMinutes ?? $this->staleMinutes;
        $stale   = now()->subMinutes($minutes);

        $paidNotIssued  = Order::paidButNotIssued($stale);
        $issuedNotPaid  = Order::issuedButNotPaid();
        $issuedNotFinal = Order::issuedButNotDelivered();
        $mismatched     = PaymentCallbackLog::amountMismatches();
        $unprocessed    = PaymentCallbackLog::unprocessedSummaryOlderThan($stale);
        $orphaned       = VendorKey::orphanedAcrossVendors();
        $money          = $this->money();

        $anomalies = $paidNotIssued->count()
                     + $issuedNotPaid->count()
                     + $issuedNotFinal->count()
                     + $mismatched->count()
                     + $unprocessed->count()
                     + $orphaned->count()
                     + ($money['balanced'] ? 0 : 1);

        return [
            'generated_at'          => now()->toIso8601ZuluString(),
            'stale_minutes'         => $minutes,
            'anomalies'             => $anomalies,
            'money'                 => $money,
            'paid_not_issued'       => $paidNotIssued,
            'issued_not_paid'       => $issuedNotPaid,
            'issued_not_delivered'  => $issuedNotFinal,
            'amount_mismatch'       => $mismatched,
            'unprocessed_callbacks' => $unprocessed,
            'orphaned_vendor_keys'  => $orphaned,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function money(): array
    {
        $paid          = Order::sumPaid();
        $delivered     = Order::sumPaidAndIssued();
        $pending       = Order::sumPaidNotIssued();
        $issuedNotPaid = Order::sumIssuedNotPaid();

        return [
            'paid_total'            => round($paid, 2),
            'delivered_total'       => round($delivered, 2),
            'pending_total'         => round($pending, 2),
            'issued_not_paid_total' => round($issuedNotPaid, 2),
            'balanced'              => abs($paid - ($delivered + $pending)) < 0.01 && abs($issuedNotPaid) < 0.01,
        ];
    }

}
