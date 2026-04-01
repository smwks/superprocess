<?php

declare(strict_types=1);

namespace SMWks\SuperProcess;

enum OutputStream: string
{
    case Stdout = 'stdout';
    case Stderr = 'stderr';
}
