<?php

namespace App\Models;

use App\Domain\Entity\ProductVendor\VendorEnum;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

class ExternalVendorKey extends Model
{
    /** @use HasFactory<\Database\Factories\Models\ExternalVendorKeyFactory> */
    use HasFactory;
    use HasUuids;

    protected $guarded = ['id'];

    protected $casts = [
        'status'      => ExternalVendorKeyStatusEnum::class,
        'vendor_name' => VendorEnum::class
    ];

    public static function issueOrReturnExisting(VendorEnum $vendorName, string $sku, string $requestId): ?self
    {
        $key = static::findByRequestId($vendorName, $requestId);

        if ($key) {
            return $key;
        }

        try {
            return DB::transaction(
                static fn(): ?self => static::reserveAvailableKey($vendorName, $sku, $requestId)
            );
        } catch (UniqueConstraintViolationException) {
            return static::findByRequestId($vendorName, $requestId);
        }
    }

    public static function findByRequestId(VendorEnum $vendorName, string $requestId): ?self
    {
        return static::query()
                     ->where('vendor_name', $vendorName->value)
                     ->where('request_id', $requestId)
                     ->first();
    }

    private static function reserveAvailableKey(VendorEnum $vendorName, string $sku, string $requestId): ?self
    {
        $key = static::query()
                     ->where('vendor_name', $vendorName->value)
                     ->where('sku', $sku)
                     ->where('status', ExternalVendorKeyStatusEnum::AVAILABLE)
                     ->whereNull('request_id')
                     ->lock('FOR UPDATE SKIP LOCKED')
                     ->first();

        if (!$key) {
            return null;
        }

        $key->update(
            [
                'request_id' => $requestId,
                'status'     => ExternalVendorKeyStatusEnum::ISSUING
            ]
        );

        return $key;
    }

    public static function randomIssuedKey(VendorEnum $vendorName, string $sku): ?self
    {
        return static::query()
                     ->where('vendor_name', $vendorName->value)
                     ->where('sku', $sku)
                     ->where('status', ExternalVendorKeyStatusEnum::ISSUED)
                     ->inRandomOrder()
                     ->first();
    }

    public static function randomForeignKey(VendorEnum $vendorName, string $sku): ?self
    {
        return static::query()
                     ->where('vendor_name', '!=', $vendorName->value)
                     ->where('sku', $sku)
                     ->orderByRaw("CASE WHEN status = ? THEN 0 ELSE 1 END", [ExternalVendorKeyStatusEnum::ISSUED->value])
                     ->inRandomOrder()
                     ->first();
    }

    /** @return \Illuminate\Support\Collection<int, object> */
    public static function orphanedIssued(): \Illuminate\Support\Collection
    {
        return collect(DB::select(<<<'SQL'
            SELECT ek.vendor_name,
                   ek.sku,
                   ek.key,
                   ek.request_id AS attempt_request_id,
                   ek.updated_at
              FROM external_vendor_keys ek
             WHERE ek.status = 'issued'
               AND ek.request_id IS NOT NULL
               AND NOT EXISTS (
                   SELECT 1 FROM vendor_keys vk
                    WHERE vk.attempt_request_id::text = ek.request_id
               )
               AND NOT EXISTS (
                   SELECT 1 FROM vendor_gift_cards gc
                    WHERE gc.attempt_request_id::text = ek.request_id
               )
             ORDER BY ek.updated_at
        SQL));
    }

    public function markAsIssued():void
    {
        $this->status = ExternalVendorKeyStatusEnum::ISSUED;
        $this->save();
    }

}
