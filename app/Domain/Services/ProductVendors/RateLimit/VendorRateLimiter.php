<?php
declare(strict_types=1);

namespace App\Domain\Services\ProductVendors\RateLimit;

use App\Domain\Entity\ProductVendor\VendorEnum;
use App\Domain\Services\ProductVendors\RateLimit\Exceptions\VendorRateLimitedException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;

readonly class VendorRateLimiter
{

    /**
     * @param  array<string, int>  $limits  лимит запросов в минуту на поставщика
     * @param  int  $counterTtlInSeconds  сколько прошедшие минуты остаются читаемыми
     */
    public function __construct(
        private array $limits,
        private int $counterTtlInSeconds,
    )
    {

    }

    public function acquire(VendorEnum $vendor): void
    {
        $limit = $this->limitFor($vendor);

        if ($limit <= 0) {
            return;
        }

        $key  = $this->key($vendor);
        $used = RateLimiter::increment($key, $this->counterTtlInSeconds);

        if ($used > $limit) {
            RateLimiter::decrement($key);

            throw new VendorRateLimitedException($vendor, $this->secondsUntilNextMinute());
        }
    }

    public function secondsUntilAvailable(VendorEnum $vendor): int
    {
        $limit = $this->limitFor($vendor);

        if ($limit <= 0) {
            return 0;
        }

        return $this->requestsInMinute($vendor) >= $limit
            ? $this->secondsUntilNextMinute()
            : 0;
    }

    public function requestsInMinute(VendorEnum $vendor, ?\DateTimeInterface $minute = null): int
    {
        return (int) RateLimiter::attempts($this->key($vendor, $minute));
    }

    public function remaining(VendorEnum $vendor): int
    {
        return $this->remainingFrom($this->limitFor($vendor), $this->requestsInMinute($vendor));
    }

    /** @return array{rpm_limit: int, remaining_in_window: int, requests_this_minute: int} */
    public function snapshot(VendorEnum $vendor): array
    {
        $limit = $this->limitFor($vendor);
        $used  = $this->requestsInMinute($vendor);

        return [
            'rpm_limit'            => $limit,
            'remaining_in_window'  => $this->remainingFrom($limit, $used),
            'requests_this_minute' => $used,
        ];
    }

    private function remainingFrom(int $limit, int $used): int
    {
        return $limit <= 0
            ? PHP_INT_MAX
            : max(0, $limit - $used);
    }

    public function limitFor(VendorEnum $vendor): int
    {
        $override = Cache::get($this->limitKey($vendor));

        return $override !== null
            ? (int) $override
            : (int) ($this->limits[$vendor->value] ?? 0);
    }

    public function overrideLimit(VendorEnum $vendor, int $limit, int $ttlSeconds = 3600): void
    {
        Cache::put($this->limitKey($vendor), $limit, $ttlSeconds);
    }

    public function dropOverride(VendorEnum $vendor): void
    {
        Cache::forget($this->limitKey($vendor));
    }

    private function secondsUntilNextMinute(): int
    {
        return max(1, 60 - (int) now()->format('s'));
    }

    private function key(VendorEnum $vendor, ?\DateTimeInterface $minute = null): string
    {
        $stamp = ($minute ?? now())->format('YmdHi');

        return "vendor:{$vendor->value}:{$stamp}";
    }

    private function limitKey(VendorEnum $vendor): string
    {
        return "vendor:{$vendor->value}:limit";
    }

}
