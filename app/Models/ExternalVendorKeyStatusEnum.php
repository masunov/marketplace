<?php

namespace App\Models;

enum ExternalVendorKeyStatusEnum: string
{

    case AVAILABLE = 'available';
    case ISSUING = 'issuing';
    case ISSUED = 'issued';

}
