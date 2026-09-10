<?php

namespace Database\Seeders;

use App\Domain\Entity\Product\ProductTypeEnum;
use App\Domain\Entity\ProductVendor\VendorEnum;
use App\Models\ExternalVendorKey;
use App\Models\ExternalVendorKeyStatusEnum;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class ExternalVendorKeySeeder extends Seeder
{

    /** @return array<string, array<int, string>> */
    private function poolsByVendor(): array
    {
        $keys = [
            'LFXC-TNCS-BPCD', 'P3EI-W8UO-9B4K', 'FEL3-GUXN-TCCH', 'YPLV-QK2Z-IUS5', '0K9E-P1FR-BY1U',
            '5LZV-UQ48-RXCZ', 'X93K-NYAQ-GEC1', 'EIO5-CQT5-35KO', 'M58F-GIIR-VJAP', 'NU8Y-SWYB-6252',
            'OODW-CCHF-MBAF', 'DNA5-WFJM-NE49', 'QRDD-MJ3F-A8TF', 'TAT9-5ZJN-G1T2', 'LI39-4330-ISMB',
            'BKJY-8Q79-8NHI', 'HHW6-4RX2-DX62', '1RG2-L28O-O80G', 'EF63-F39X-MTEA', '8XS7-P53H-JKIV',
            'JPE6-MQV6-P7ST', 'SAPG-A2GR-0ULS', 'T2DU-IJ1S-U16P', 'WSSY-QTR7-Z57J', 'U74E-EPCI-CY26',
            'FZXF-58H8-OR93', 'FPSM-HLZA-TPAL', 'WSC9-28DJ-B2JE', 'P63J-F7UZ-DCYP', 'C7W2-D4C5-QMT7',
            'JESI-DFBH-LK1K', 'SGMA-JA0T-GR7D', '3PR4-OSY9-M3ZW', 'OMBE-C0JF-D45Y', 'KIKQ-FQJ8-9TI8',
            'LMAN-RSHS-AJDO', 'BAKI-VT1X-Z5OL', '9F0X-B46W-03FS', 'S423-V6YY-IBEM', 'D4UW-WYRA-20ST',
            'XC0J-CJ0H-09RN', 'RY1W-XCFJ-0KUA', 'CJYY-YKSQ-QE6H', '97AQ-38QJ-H8HU', 'FS8E-3S5Z-I6RA',
            'ARQK-FML4-A14E', '7Z6K-NO9V-MPJB', 'D4K7-IJSG-N853', 'W67T-ZB0Q-1XKB', '7EQM-K09J-XKUO',
        ];

        $half = (int)ceil(count($keys) / 2);
        $size = $this->poolSize();

        return [
            VendorEnum::VENDOR_A->value => [
                ProductTypeEnum::GIFTCARD->value => Collection::times($size, fn() => $this->generateKey(segments: 4, segmentLength: 5))->all(),
                ProductTypeEnum::KEY->value      => array_merge(
                    array_slice($keys, 0, $half),
                    Collection::times($size - $half, fn() => $this->generateKey())->all()
                )
            ],
            VendorEnum::VENDOR_B->value => [
                ProductTypeEnum::GIFTCARD->value => Collection::times($size, fn() => $this->generateKey(segments: 4, segmentLength: 5))->all(),
                ProductTypeEnum::KEY->value      => array_merge(
                    array_slice($keys, $half),
                    Collection::times($size - $half, fn() => $this->generateKey())->all()
                )
            ],
            VendorEnum::VENDOR_C->value => [
                ProductTypeEnum::GIFTCARD->value => Collection::times($size, fn() => $this->generateKey(segments: 4, segmentLength: 5))->all(),
                ProductTypeEnum::KEY->value      => Collection::times($size, fn() => $this->generateKey())->all()
            ],
        ];
    }

    public function run(): void
    {
        ExternalVendorKey::query()->insertOrIgnore($this->rows());
    }

    /** @return array<int, array<string, mixed>> */
    private function rows(): array
    {
        $skusList = [
            ProductTypeEnum::GIFTCARD->value => [
                'GIFT-PSN-1000',
                'GIFT-XBOX-1500',
                'GIFT-ROBLOX-800',
            ],
            ProductTypeEnum::KEY->value      => [
                'KEY-CS2-PRIME',
                'KEY-GTA5',
                'KEY-EFT',
            ]
        ];
        $now      = now();
        $rows     = [];
        $index    = 0;

        foreach ($this->poolsByVendor() as $vendorName => $productKeys) {
            foreach ($skusList as $productType => $skus) {
                if ($vendorName === VendorEnum::VENDOR_C->value && $productType === ProductTypeEnum::GIFTCARD->value) {
                    $skus[] = 'GIFT-APPSTORE-1000';
                }
                foreach ($productKeys[$productType] as $key) {
                    $rows[] = [
                        'id'          => (string)Str::uuid7(),
                        'key'         => $key,
                        'sku'         => $skus[$index++ % count($skus)],
                        'vendor_name' => $vendorName,
                        'status'      => ExternalVendorKeyStatusEnum::AVAILABLE->value,
                        'request_id'  => null,
                        'created_at'  => $now,
                        'updated_at'  => $now,
                    ];
                }
            }
        }

        return $rows;
    }

    private function poolSize(): int
    {
        return (int) env('VENDOR_POOL_SIZE', 300);
    }

    private function generateKey(int $segments = 3, int $segmentLength = 4): string
    {
        return collect(range(1, $segments))
            ->map(fn() => Str::upper(Str::random($segmentLength)))
            ->implode('-');
    }

}
