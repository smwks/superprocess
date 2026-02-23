<?php

declare(strict_types=1);

namespace SMWks\SuperProcess;

enum CreateReason: string
{
    case Initial = 'INITIAL';
    case Replacement = 'REPLACEMENT';
    case ScaleUp = 'SCALE_UP';
}
