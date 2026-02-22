<?php

declare(strict_types=1);

namespace SMWks\Superprocess;

final class ProcessSignal
{
    public const int STOP = SIGTERM;

    public const int KILL = SIGKILL;

    public const int RELOAD = SIGHUP;

    public const int USR1 = SIGUSR1;

    public const int USR2 = SIGUSR2;
}
