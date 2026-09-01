<?php

namespace App\Domain\Entity\Product;

use App\Domain\Entity\Currency\CurrencyEnum;
use App\Domain\Entity\ProductVendor\Vendor;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Product extends Model
{
    /** @use HasFactory<\Database\Factories\Domain\Entity\Product\ProductFactory> */
    use HasFactory;
    use HasUuids;

    protected $guarded = ['id'];

    protected $casts = [
        'type'     => ProductTypeEnum::class,
        'currency' => CurrencyEnum::class,
        'price'    => 'decimal:2',
    ];

    public function vendors(): BelongsToMany
    {
        return $this->belongsToMany(Vendor::class, 'product2vendors', 'product_id', 'vendor_id')
                    ->withPivot(['priority', 'max_attempts'])
                    ->orderByPivot('priority');
    }

    public static function findBySku(string $sku): ?self
    {
        return static::query()->where('sku', $sku)->first();
    }

}
