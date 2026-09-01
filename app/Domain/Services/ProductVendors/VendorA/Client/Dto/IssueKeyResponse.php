<?php
declare(strict_types=1);

namespace App\Domain\Services\ProductVendors\VendorA\Client\Dto;

readonly class IssueKeyResponse
{

    public const string SUCCESS = 'success';
    public const string FAIL  = 'fail';
    public const string ERROR = 'error';

    private function __construct(
        public string  $status,
        public string  $requestId,
        public ?string $code = null,
        public ?string $reason = null,
    ) {
    }

    public static function buildForSuccess(string $requestId, string $code): self
    {
        return new self(status: self::SUCCESS, requestId: $requestId, code: $code);
    }

    public static function buildForError(string $requestId, string $reason): self
    {
        return new self(status: self::ERROR, requestId: $requestId, reason: $reason);
    }

    public static function buildForFail(string $requestId, string $reason): self
    {
        return new self(status: self::FAIL, requestId: $requestId, reason: $reason);
    }

}
