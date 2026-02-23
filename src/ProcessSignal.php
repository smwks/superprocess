<?php

declare(strict_types=1);

namespace SMWks\SuperProcess;

enum ProcessSignal
{
    case Stop;
    case Kill;
    case Reload;
    case Usr1;
    case Usr2;

    public function signum(): int
    {
        return match ($this) {
            ProcessSignal::Stop => SIGTERM,
            ProcessSignal::Kill => SIGKILL,
            ProcessSignal::Reload => SIGHUP,
            ProcessSignal::Usr1 => SIGUSR1,
            ProcessSignal::Usr2 => SIGUSR2,
        };
    }
}
