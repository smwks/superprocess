<?php

declare(strict_types=1);

namespace SMWks\SuperProcess;

enum ProcessSignal: string
{
    case Stop = 'STOP';
    case Kill = 'KILL';
    case Reload = 'RELOAD';
    case Usr1 = 'USR1';
    case Usr2 = 'USR2';

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
