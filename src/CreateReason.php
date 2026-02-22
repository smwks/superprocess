<?php

declare(strict_types=1);

namespace SMWks\Superprocess;

enum CreateReason
{
    case Initial;
    case Replacement;
    case ScaleUp;
}
