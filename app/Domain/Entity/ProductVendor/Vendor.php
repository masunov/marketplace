<?php

namespace App\Domain\Entity\ProductVendor;

use App\Domain\Entity\Product\Product;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Vendor extends Model
{
    /** @use HasFactory<\Database\Factories\Domain\Entity\ProductVendor\VendorFactory> */
    use HasFactory;
    use HasUuids;

    protected $guarded = ['id'];

    protected $casts = [
        'name' => VendorEnum::class,
    ];

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'product2vendors', 'vendor_id', 'product_id')
                    ->withPivot(['priority', 'max_attempts']);
    }

}
