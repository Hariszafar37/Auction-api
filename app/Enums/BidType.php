<?php

namespace App\Enums;

enum BidType: string
{
    case Manual     = 'manual';      // buyer placed manually
    case Proxy      = 'proxy';       // buyer set max bid (first activation)
    case Auto       = 'auto';        // system placed on behalf of proxy
    case Auctioneer = 'auctioneer';  // admin/auctioneer placed bid
}
