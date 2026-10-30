<?php

namespace App\Support;

enum PartnerPostbackResult
{
    case Sent;
    case RetryableHttpFailure;
    case NetworkFailure;
    case Timeout;
    case PermanentFailure;
}
