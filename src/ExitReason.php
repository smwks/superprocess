<?php

declare(strict_types=1);

namespace SMWks\SuperProcess;

enum ExitReason: string
{
    case Normal = 'NORMAL';
    case Signal = 'SIGNAL';
    case Killed = 'KILLED';
    case Unknown = 'UNKNOWN';
}
