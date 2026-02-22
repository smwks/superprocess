<?php

declare(strict_types=1);

namespace SMWks\Superprocess;

final class Child
{
    public bool $running = true;

    public int $exitCode = 0;

    public ExitReason $exitReason = ExitReason::Unknown;

    /**
     * @param  resource|null  $processHandle  proc_open handle (commands only)
     * @param  resource|null  $stdin  writable by master (sendChildInput)
     * @param  resource|null  $stdout  readable by master (onChildOutput)
     * @param  resource|null  $stderr  readable by master (onChildOutput)
     * @param  resource|null  $ipcChannel  structured messages (onChildMessage)
     */
    public function __construct(
        public readonly int $pid,
        public readonly CreateReason $createReason,
        public readonly mixed $processHandle,
        public readonly mixed $stdin,
        public readonly mixed $stdout,
        public readonly mixed $stderr,
        public readonly mixed $ipcChannel,
    ) {}

    /** @return list<resource> */
    public function readableStreams(): array
    {
        $streams = [];

        if ($this->stdout !== null) {
            $streams[] = $this->stdout;
        }

        if ($this->stderr !== null) {
            $streams[] = $this->stderr;
        }

        if ($this->ipcChannel !== null) {
            $streams[] = $this->ipcChannel;
        }

        return $streams;
    }
}
