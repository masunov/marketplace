<?php

namespace Database\Factories\Models;

use App\Domain\Entity\ProductVendor\VendorEnum;
use App\Models\ExternalVendorKey;
use App\Models\ExternalVendorKeyStatusEnum;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ExternalVendorKey> */
class ExternalVendorKeyFactory extends Factory
{
    protected $model = ExternalVendorKey::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'key'         => $this->generateKey(),
            'vendor_name' => VendorEnum::VENDOR_A,
            'status'      => ExternalVendorKeyStatusEnum::AVAILABLE,
            'request_id'  => null,
        ];
    }

    public function forVendor(VendorEnum $vendor): self
    {
        return $this->state(fn() => ['vendor_name' => $vendor]);
    }

    private function generateKey(): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';

        $group = fn() => collect(range(1, 4))
            ->map(fn() => $alphabet[random_int(0, strlen($alphabet) - 1)])
            ->implode('');

        return implode('-', [$group(), $group(), $group()]);
    }
}
