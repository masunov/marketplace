<?php
declare(strict_types=1);

namespace App\Domain\Services\ProductVendors;

enum VendorIssueOutcomeEnum: string
{

    case SUCCESS = 'success';
    case TIMEOUT = 'timeout';
    case ERROR = 'error';
    case OUT_OF_STOCK = 'out_of_stock';

}
