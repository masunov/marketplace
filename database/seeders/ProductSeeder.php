<?php

namespace Database\Seeders;

use App\Domain\Entity\Currency\CurrencyEnum;
use App\Domain\Entity\Product\Product;
use App\Domain\Entity\Product\ProductTypeEnum;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class ProductSeeder extends Seeder
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public static function catalogue(): array
    {
        return [
            ['sku' => 'STEAM-TOPUP-500', 'name' => 'Пополнение Steam 500 ₽', 'type' => ProductTypeEnum::TOPUP, 'price' => 500, 'image' => 'assets/steam.png'],
            ['sku' => 'STEAM-TOPUP-1000', 'name' => 'Пополнение Steam 1000 ₽', 'type' => ProductTypeEnum::TOPUP, 'price' => 1000, 'image' => 'assets/steam.png'],
            ['sku' => 'STEAM-TOPUP-2500', 'name' => 'Пополнение Steam 2500 ₽', 'type' => ProductTypeEnum::TOPUP, 'price' => 2500, 'image' => 'assets/steam.png'],
            ['sku' => 'KEY-CS2-PRIME', 'name' => 'CS2 Prime Status ключ', 'type' => ProductTypeEnum::KEY, 'price' => 1290, 'image' => 'assets/cs2.png'],
            ['sku' => 'KEY-GTA5', 'name' => 'GTA V ключ активации', 'type' => ProductTypeEnum::KEY, 'price' => 1990, 'image' => 'assets/gta5.png'],
            ['sku' => 'KEY-EFT', 'name' => 'Escape from Tarkov ключ', 'type' => ProductTypeEnum::KEY, 'price' => 3490, 'image' => 'assets/eft.png'],
            ['sku' => 'SUB-DISCORD-1M', 'name' => 'Discord Nitro 1 месяц', 'type' => ProductTypeEnum::SUBSCRIPTION, 'price' => 399, 'image' => 'assets/discord.png'],
            ['sku' => 'SUB-YT-3M', 'name' => 'YouTube Premium 3 месяца', 'type' => ProductTypeEnum::SUBSCRIPTION, 'price' => 1490, 'image' => 'assets/youtube.png'],
            ['sku' => 'SUB-SPOTIFY-1M', 'name' => 'Spotify Premium 1 месяц', 'type' => ProductTypeEnum::SUBSCRIPTION, 'price' => 299, 'image' => 'assets/spotify.png'],
            ['sku' => 'GIFT-PSN-1000', 'name' => 'PlayStation Store карта 1000 ₽', 'type' => ProductTypeEnum::GIFTCARD, 'price' => 1000, 'image' => 'assets/psn.png'],
            ['sku' => 'GIFT-XBOX-1500', 'name' => 'Xbox Gift Card 1500 ₽', 'type' => ProductTypeEnum::GIFTCARD, 'price' => 1500, 'image' => 'assets/xbox.png'],
            ['sku' => 'GIFT-ROBLOX-800', 'name' => 'Roblox 800 Robux', 'type' => ProductTypeEnum::GIFTCARD, 'price' => 890, 'image' => 'assets/roblox.png'],
        ];
    }

    public function run(): void
    {
        Product::query()->upsert(
            $this->rows(),
            ['sku'],
            ['name', 'type', 'price', 'currency', 'image', 'updated_at']
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function rows(): array
    {
        $now = now();

        return array_map(
            static fn(array $product): array => [
                'id'         => (string) Str::uuid7(),
                'sku'        => $product['sku'],
                'name'       => $product['name'],
                'type'       => $product['type']->value,
                'price'      => $product['price'],
                'currency'   => CurrencyEnum::RUB->value,
                'image'      => $product['image'],
                'created_at' => $now,
                'updated_at' => $now,
            ],
            static::catalogue()
        );
    }
}
