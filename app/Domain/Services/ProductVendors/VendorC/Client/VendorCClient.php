<?php
declare(strict_types=1);

namespace App\Domain\Services\ProductVendors\VendorC\Client;

use App\Domain\Entity\ProductVendor\VendorEnum;
use App\Domain\Services\ProductVendors\VendorC\Client\Dto\IssueKeyResponse;
use App\Domain\Services\ProductVendors\VendorC\Client\Exceptions\TimeoutException;
use App\Models\ExternalVendorErrorReasonEnum;
use App\Models\ExternalVendorKey;
use Illuminate\Support\Facades\Log;

readonly class VendorCClient
{

    private const string OUTCOME_LYING_ERROR = 'lying_error';
    private const string OUTCOME_DUPLICATE = 'duplicate';
    private const string OUTCOME_FOREIGN_CODE = 'foreign_code';
    private const string OUTCOME_ERROR = 'error';
    private const string OUTCOME_TIMEOUT = 'timeout';
    private const string OUTCOME_SUCCESS = 'success';

    public function __construct(
        private int $totalChances,
        private int $backendErrorChance,
        private int $timeoutErrorChance,
        private int $duplicateChance,
        private int $foreignCodeChance,
        private int $lyingErrorChance
    ) {
    }

    public function issueKey(string $orderId, string $sku, string $requestId): IssueKeyResponse
    {
        $outcome = $this->rollOutcome();

        return match ($outcome) {
            self::OUTCOME_LYING_ERROR  => $this->lieAboutError($orderId, $sku, $requestId),
            self::OUTCOME_DUPLICATE    => $this->issueDuplicate($orderId, $sku, $requestId),
            self::OUTCOME_FOREIGN_CODE => $this->issueForeignCode($orderId, $sku, $requestId),
            self::OUTCOME_ERROR        => $this->failWithServerError($orderId, $sku, $requestId),
            default                    => $this->issueHonestly($orderId, $sku, $requestId, $outcome),
        };
    }

    public function checkKey(string $orderId, string $requestId): IssueKeyResponse
    {
        if ($this->rollsInto($this->backendErrorChance)) {
            $this->log('error', 'server error while checking key', $orderId, null, $requestId, 'Server error');

            return IssueKeyResponse::buildForFail($requestId, 'Server error');
        }

        $key = ExternalVendorKey::findByRequestId(VendorEnum::VENDOR_C, $requestId);

        if ($key) {
            $key->markAsIssued();

            return IssueKeyResponse::buildForSuccess($requestId, $key->key);
        }

        $this->log('info', 'confirms key was not issued', $orderId, null, $requestId, 'not_issued');

        return IssueKeyResponse::buildForError($requestId, 'not_issued');
    }

    private function lieAboutError(string $orderId, string $sku, string $requestId): IssueKeyResponse
    {
        $key = ExternalVendorKey::issueOrReturnExisting(VendorEnum::VENDOR_C, $sku, $requestId);

        if (!$key) {
            return $this->outOfStock($orderId, $sku, $requestId);
        }

        $key->markAsIssued();

        $this->log('error', 'reports error but the key was issued', $orderId, $sku, $requestId, 'lying_error');

        return IssueKeyResponse::buildForFail($requestId, 'Server error');
    }

    private function issueDuplicate(string $orderId, string $sku, string $requestId): IssueKeyResponse
    {
        $key = ExternalVendorKey::randomIssuedKey(VendorEnum::VENDOR_C, $sku);

        if (!$key) {
            return $this->issueHonestly($orderId, $sku, $requestId, self::OUTCOME_SUCCESS);
        }

        $this->log('error', 'returns a duplicate key', $orderId, $sku, $requestId, 'duplicate');

        return IssueKeyResponse::buildForSuccess($requestId, $key->key);
    }

    private function issueForeignCode(string $orderId, string $sku, string $requestId): IssueKeyResponse
    {
        $key = ExternalVendorKey::randomForeignKey(VendorEnum::VENDOR_C, $sku);

        if (!$key) {
            return $this->issueHonestly($orderId, $sku, $requestId, self::OUTCOME_SUCCESS);
        }

        $this->log('error', 'returns a foreign key', $orderId, $sku, $requestId, 'foreign_code');

        return IssueKeyResponse::buildForSuccess($requestId, $key->key);
    }

    private function failWithServerError(string $orderId, string $sku, string $requestId): IssueKeyResponse
    {
        $this->log('error', 'server error while issuing key', $orderId, $sku, $requestId, 'Server error');

        return IssueKeyResponse::buildForFail($requestId, 'Server error');
    }

    private function issueHonestly(
        string $orderId,
        string $sku,
        string $requestId,
        string $outcome
    ): IssueKeyResponse
    {
        $key = ExternalVendorKey::issueOrReturnExisting(VendorEnum::VENDOR_C, $sku, $requestId);

        if (!$key) {
            return $this->outOfStock($orderId, $sku, $requestId);
        }

        if ($outcome === self::OUTCOME_TIMEOUT) {
            $this->log('error', 'timeout while issuing key', $orderId, $sku, $requestId, 'Timeout');

            throw new TimeoutException('Timeout from vendor C in issueKey request exception');
        }

        $key->markAsIssued();

        return IssueKeyResponse::buildForSuccess($requestId, $key->key);
    }

    private function outOfStock(string $orderId, string $sku, string $requestId): IssueKeyResponse
    {
        $this->log('info', 'out_of_stock while issuing key', $orderId, $sku, $requestId, 'out_of_stock');

        return IssueKeyResponse::buildForError($requestId, ExternalVendorErrorReasonEnum::OUT_OF_STOCK->value);
    }

    private function rollOutcome(): string
    {
        $roll   = random_int(1, $this->totalChances);
        $cursor = 0;

        foreach ($this->outcomeWeights() as $outcome => $weight) {
            $cursor += $weight;

            if ($roll <= $cursor) {
                return $outcome;
            }
        }

        return self::OUTCOME_SUCCESS;
    }

    /** @return array<string, int> */
    private function outcomeWeights(): array
    {
        return [
            self::OUTCOME_LYING_ERROR  => $this->lyingErrorChance,
            self::OUTCOME_DUPLICATE    => $this->duplicateChance,
            self::OUTCOME_FOREIGN_CODE => $this->foreignCodeChance,
            self::OUTCOME_ERROR        => $this->backendErrorChance,
            self::OUTCOME_TIMEOUT      => $this->timeoutErrorChance,
        ];
    }

    private function rollsInto(int $chance): bool
    {
        return random_int(1, $this->totalChances) <= $chance;
    }

    private function log(
        string $level,
        string $message,
        string $orderId,
        ?string $sku,
        string $requestId,
        string $reason
    ): void
    {
        Log::{$level}("Vendor C {$message}", array_filter([
            'order_id'   => $orderId,
            'sku'        => $sku,
            'request_id' => $requestId,
            'vendor'     => VendorEnum::VENDOR_C->value,
            'reason'     => $reason,
        ]));
    }

}
