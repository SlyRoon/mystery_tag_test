<?php

namespace App\Support;

enum PostbackAcceptanceOutcome
{
    case Accepted;
    case Duplicate;
    case Unavailable;
}
