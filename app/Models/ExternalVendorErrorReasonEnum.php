<?php

namespace App\Models;

enum ExternalVendorErrorReasonEnum: string
{

    case SERVER_ERROR = 'server_error';
    case TIMEOUT = 'timeout';
    case OUT_OF_STOCK = 'out_of_stock';

}
