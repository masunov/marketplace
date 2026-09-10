<?php

namespace App\Domain\Entity\PaymentSystem;

use App\Domain\Entity\Currency\CurrencyEnum;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class PaymentCallbackLog extends Model
{
    /** @use HasFactory<\Database\Factories\Domain\Entity\PaymentSystem\PaymentCallbackLogFactory> */
    use HasFactory;
    use HasUuids;

    protected $guarded = ['id'];

    protected $casts = [
        'payment_system' => PaymentSystemEnum::class,
        'payment_status' => PaymentStatusEnum::class,
        'currency'       => CurrencyEnum::class,
        'payload'        => 'array',
        'amount'         => 'decimal:2',
        'ps_created_at'  => 'immutable_datetime',
        'processed_at'   => 'immutable_datetime',
    ];

    public static function findExistingCallback(
        string            $psCallbackId,
        PaymentSystemEnum $paymentSystem
    ): ?self {
        return static::query()
                     ->where('ps_callback_id', $psCallbackId)
                     ->where('payment_system', $paymentSystem->value)
                     ->first();
    }

    public function markProcessed(): void
    {
        $this->processed_at = now();
        $this->save();
    }

    /** @return Collection<int, self> */
    public static function unprocessedOlderThan(\DateTimeInterface $stale, int $limit): Collection
    {
        return static::query()
                     ->whereNull('processed_at')
                     ->where('created_at', '<=', $stale)
                     ->orderBy('ps_created_at')
                     ->limit($limit)
                     ->get();
    }

    /** @return \Illuminate\Support\Collection<int, object> */
    public static function amountMismatches(): \Illuminate\Support\Collection
    {
        return DB::table('payment_callback_logs as p')
                 ->join('orders as o', 'o.id', '=', 'p.order_id')
                 ->select('p.ps_callback_id', 'p.order_id', 'p.amount', 'p.currency', 'o.total_amount', 'o.currency as order_currency')
                 ->where('p.payment_status', PaymentStatusEnum::PAID->value)
                 ->whereNotNull('p.processed_at')
                 ->where(static function ($query): void {
                     $query->whereColumn('p.amount', '!=', 'o.total_amount')
                           ->orWhereColumn('p.currency', '!=', 'o.currency');
                 })
                 ->get();
    }

    /** @return \Illuminate\Support\Collection<int, object> */
    public static function unprocessedSummaryOlderThan(\DateTimeInterface $stale): \Illuminate\Support\Collection
    {
        return DB::table('payment_callback_logs')
                 ->select('ps_callback_id', 'order_id', 'payment_status', 'amount', 'created_at')
                 ->whereNull('processed_at')
                 ->where('created_at', '<=', $stale)
                 ->orderBy('ps_created_at')
                 ->get();
    }

}
