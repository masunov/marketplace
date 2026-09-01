<?php

namespace Database\Seeders;

use App\Domain\Entity\Product\Product;
use App\Domain\Entity\ProductVendor\Vendor;
use App\Domain\Entity\ProductVendor\VendorEnum;
use Illuminate\Database\Seeder;

class VendorSeeder extends Seeder
{
    /**
     * @return array<string, array<string, mixed>>
     */
    public static function vendors(): array
    {
        return [
            VendorEnum::VENDOR_A->value => ['title' => 'Vendor A', 'priority' => 0, 'max_attempts' => 3],
            VendorEnum::VENDOR_B->value => ['title' => 'Vendor B', 'priority' => 1, 'max_attempts' => 2],
        ];
    }

    public function run(): void
    {
        $products = Product::query()->get();

        foreach (static::vendors() as $name => $settings) {
            $vendor = Vendor::query()->updateOrCreate(
                ['name' => $name],
                ['title' => $settings['title']]
            );

            foreach ($products as $product) {
                $vendor->products()->syncWithoutDetaching([
                    $product->getKey() => [
                        'priority'     => $settings['priority'],
                        'max_attempts' => $settings['max_attempts'],
                    ],
                ]);
            }
        }
    }
}
