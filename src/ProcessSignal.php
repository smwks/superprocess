<?php

declare(strict_types=1);

namespace SMWks\SuperProcess;

enum ProcessSignal: int
{
    case Stop = 15; // SIGTERM;
    case Kill = 9; // SIGKILL;
    case Reload = 1; // SIGHUP;
    case Usr1 = 30; // SIGUSR1;
    case Usr2 = 31; // SIGUSR2;
}
