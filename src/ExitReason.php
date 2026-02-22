<?php

declare(strict_types=1);

namespace SMWks\Superprocess;

enum ExitReason
{
    case Normal;
    case Signal;
    case Killed;
    case Unknown;
}
