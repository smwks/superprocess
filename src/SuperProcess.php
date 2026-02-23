<?php

declare(strict_types=1);

namespace SMWks\SuperProcess;

use Closure;
use SMWks\SuperProcess\Exceptions\ProcessException;

class SuperProcess
{
    protected ?string $command = null;

    protected ?Closure $closure = null;

    protected int $minChildren = 1;

    protected int $maxChildren = 1;

    protected int $heartbeatInterval = 0;

    protected ?Closure $heartbeatCallback = null;

    protected ?Closure $onChildCreateCallback = null;

    protected ?Closure $onChildExitCallback = null;

    protected ?Closure $onChildSignalCallback = null;

    protected ?Closure $onChildMessageCallback = null;

    protected ?Closure $onChildOutputCallback = null;

    /** @var array<int, Child> */
    protected array $children = [];

    protected bool $sigchldPending = false;

    protected bool $shutdownPending = false;

    public function command(string $command): static
    {
        $this->command = $command;

        return $this;
    }

    public function closure(Closure $closure): static
    {
        $this->closure = $closure;

        return $this;
    }

    public function heartbeat(int $intervalSeconds, Closure $callback): static
    {
        $this->heartbeatInterval = $intervalSeconds;
        $this->heartbeatCallback = $callback;

        return $this;
    }

    public function scaleLimits(int $min, int $max): static
    {
        $this->minChildren = $min;
        $this->maxChildren = $max;

        return $this;
    }

    public function scaleUp(): static
    {
        if (count($this->children) < $this->maxChildren) {
            $child = $this->spawnChild(CreateReason::ScaleUp);
            $this->children[$child->pid] = $child;
            $this->fireOnChildCreate($child);
        }

        return $this;
    }

    public function scaleDown(): static
    {
        $active = array_filter($this->children, fn (Child $c) => ! $c->terminating);

        if (count($active) > $this->minChildren) {
            $child = reset($active);
            if ($child instanceof Child) {
                $child->terminating = true;
                posix_kill($child->pid, SIGTERM);
            }
        }

        return $this;
    }

    public function onChildCreate(Closure $callback): static
    {
        $this->onChildCreateCallback = $callback;

        return $this;
    }

    public function onChildExit(Closure $callback): static
    {
        $this->onChildExitCallback = $callback;

        return $this;
    }

    public function onChildSignal(Closure $callback): static
    {
        $this->onChildSignalCallback = $callback;

        return $this;
    }

    public function onChildMessage(Closure $callback): static
    {
        $this->onChildMessageCallback = $callback;

        return $this;
    }

    public function onChildOutput(Closure $callback): static
    {
        $this->onChildOutputCallback = $callback;

        return $this;
    }

    public function sendChildInput(int $pid, string $data): void
    {
        $child = $this->children[$pid] ?? null;
        if ($child instanceof Child && $child->stdin !== null) {
            fwrite($child->stdin, $data);
        }
    }

    public function signal(string|int $pid, ProcessSignal $signal): void
    {
        posix_kill((int) $pid, $signal->value);
    }

    public function run(): void
    {
        if ($this->command === null && ! $this->closure instanceof \Closure) {
            throw new ProcessException('No command or closure configured. Call command() or closure() before run().');
        }

        pcntl_async_signals(true);

        pcntl_signal(SIGCHLD, function (): void {
            $this->sigchldPending = true;
        });

        pcntl_signal(SIGTERM, function (): void {
            $this->shutdownPending = true;
        });

        pcntl_signal(SIGHUP, function (): void {
            // SIGHUP: reload / restart children gracefully
            foreach ($this->children as $child) {
                posix_kill($child->pid, SIGHUP);
            }
        });

        pcntl_signal(SIGUSR1, function (): void {
            $this->dispatchChildSignalToAll(SIGUSR1);
        });

        pcntl_signal(SIGUSR2, function (): void {
            $this->dispatchChildSignalToAll(SIGUSR2);
        });

        // Spawn initial children
        for ($i = 0; $i < $this->minChildren; $i++) {
            $child = $this->spawnChild(CreateReason::Initial);
            $this->children[$child->pid] = $child;
            $this->fireOnChildCreate($child);
        }

        $lastHeartbeat = time();

        while (! $this->shutdownPending) {
            // Collect readable streams from all children
            $read = [];
            foreach ($this->children as $child) {
                foreach ($child->readableStreams() as $stream) {
                    $read[] = $stream;
                }
            }

            if ($read !== []) {
                $write = null;
                $except = null;
                $changed = @stream_select($read, $write, $except, 1);

                if ($changed !== false && $changed > 0) {
                    foreach ($read as $stream) {
                        $this->dispatchStreamData($stream);
                    }
                }
            } else {
                // No streams; still honor 1-second tick for heartbeat and signal checks
                usleep(100_000);
            }

            // Heartbeat
            if ($this->heartbeatInterval > 0 && $this->heartbeatCallback instanceof \Closure && time() - $lastHeartbeat >= $this->heartbeatInterval) {
                ($this->heartbeatCallback)($this);
                $lastHeartbeat = time();
            }

            // Reap dead children and spawn replacements
            if ($this->sigchldPending) {
                $this->sigchldPending = false;
                $this->reapChildren();
                $this->maintainMinimum();
            }
        }

        $this->shutdown();
    }

    protected function spawnChild(CreateReason $reason): Child
    {
        if ($this->command !== null) {
            return $this->spawnCommandChild($reason);
        }

        return $this->spawnClosureChild($reason);
    }

    protected function spawnCommandChild(CreateReason $reason): Child
    {
        $descriptors = [
            0 => ['pipe', 'r'],  // stdin  – master writes
            1 => ['pipe', 'w'],  // stdout – master reads
            2 => ['pipe', 'w'],  // stderr – master reads
            3 => ['pipe', 'w'],  // fd3    – structured messages (onChildMessage)
        ];

        $pipes = [];
        $process = proc_open((string) $this->command, $descriptors, $pipes);

        if (! is_resource($process)) {
            throw new ProcessException(sprintf('Failed to start command: %s', $this->command));
        }

        $status = proc_get_status($process);
        $pid = $status['pid'];

        stream_set_blocking($pipes[0], false);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        stream_set_blocking($pipes[3], false);

        return new Child(
            pid: $pid,
            createReason: $reason,
            processHandle: $process,
            stdin: $pipes[0],
            stdout: $pipes[1],
            stderr: $pipes[2],
            ipcChannel: $pipes[3],
        );
    }

    protected function spawnClosureChild(CreateReason $reason): Child
    {
        $socketPair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);

        if ($socketPair === false) {
            throw new ProcessException('Failed to create socket pair for closure child IPC.');
        }

        [$parentSocket, $childSocket] = $socketPair;

        $pid = pcntl_fork();

        if ($pid === -1) {
            fclose($parentSocket);
            fclose($childSocket);
            throw new ProcessException('pcntl_fork() failed.');
        }

        if ($pid === 0) {
            // Child process
            fclose($parentSocket);
            assert($this->closure instanceof \Closure);
            try {
                ($this->closure)($childSocket);
            } finally {
                fclose($childSocket);
            }
            exit(0);
        }

        // Parent process
        fclose($childSocket);
        stream_set_blocking($parentSocket, false);

        return new Child(
            pid: $pid,
            createReason: $reason,
            processHandle: null,
            stdin: null,
            stdout: null,
            stderr: null,
            ipcChannel: $parentSocket,
        );
    }

    protected function dispatchStreamData(mixed $stream): void
    {
        if (! is_resource($stream)) {
            return;
        }

        $child = $this->findChildByStream($stream);

        if (! $child instanceof Child) {
            return;
        }

        $data = fread($stream, 8192);

        if ($data === false || $data === '') {
            return;
        }

        if ($stream === $child->ipcChannel) {
            $this->dispatchChildMessage($child, $data);
        } elseif ($this->onChildOutputCallback instanceof \Closure) {
            // stdout or stderr
            ($this->onChildOutputCallback)($child, $data);
        }
    }

    protected function dispatchChildMessage(Child $child, string $rawData): void
    {
        if (! $this->onChildMessageCallback instanceof \Closure) {
            return;
        }

        foreach (explode("\n", trim($rawData)) as $line) {
            if ($line === '') {
                continue;
            }

            $message = json_decode($line, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                ($this->onChildMessageCallback)($child, $message);
            }
        }
    }

    protected function dispatchChildSignalToAll(int $signal): void
    {
        if (! $this->onChildSignalCallback instanceof \Closure) {
            return;
        }

        foreach ($this->children as $child) {
            ($this->onChildSignalCallback)($child, $signal);
        }
    }

    protected function reapChildren(): void
    {
        while (true) {
            $status = 0;
            $pid = pcntl_waitpid(-1, $status, WNOHANG);

            if ($pid <= 0) {
                break;
            }

            $child = $this->children[$pid] ?? null;

            if ($child === null) {
                continue;
            }

            $statusInt = is_int($status) ? $status : 0;
            $exitReason = $this->resolveExitReason($statusInt);
            $rawCode = pcntl_wifexited($statusInt) ? pcntl_wexitstatus($statusInt) : 0;
            $exitCode = $rawCode !== false ? $rawCode : 0;

            $this->closeChildStreams($child);

            unset($this->children[$pid]);

            // Reflect exit state on the Child object (create an updated copy for callback)
            $exitedChild = new Child(
                pid: $child->pid,
                createReason: $child->createReason,
                processHandle: null,
                stdin: null,
                stdout: null,
                stderr: null,
                ipcChannel: null,
            );

            $exitedChild->running = false;
            $exitedChild->exitCode = $exitCode;
            $exitedChild->exitReason = $exitReason;

            if ($this->onChildExitCallback instanceof \Closure) {
                ($this->onChildExitCallback)($exitedChild, $exitReason);
            }
        }
    }

    protected function resolveExitReason(int $status): ExitReason
    {
        if (pcntl_wifexited($status)) {
            return pcntl_wexitstatus($status) === 0 ? ExitReason::Normal : ExitReason::Normal;
        }

        if (pcntl_wifsignaled($status)) {
            $sig = pcntl_wtermsig($status);

            return $sig === SIGKILL ? ExitReason::Killed : ExitReason::Signal;
        }

        return ExitReason::Unknown;
    }

    protected function maintainMinimum(): void
    {
        $running = count($this->children);

        while ($running < $this->minChildren) {
            $child = $this->spawnChild(CreateReason::Replacement);
            $this->children[$child->pid] = $child;
            $this->fireOnChildCreate($child);
            $running++;
        }
    }

    protected function closeChildStreams(Child $child): void
    {
        foreach ([$child->stdin, $child->stdout, $child->stderr, $child->ipcChannel] as $stream) {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        if (is_resource($child->processHandle)) {
            proc_close($child->processHandle);
        }
    }

    protected function shutdown(): void
    {
        // Send SIGTERM to all running children
        foreach ($this->children as $child) {
            posix_kill($child->pid, SIGTERM);
        }

        // Wait up to 5 seconds for graceful exit, then SIGKILL
        $deadline = time() + 5;

        while ($this->children !== [] && time() < $deadline) {
            $pid = pcntl_waitpid(-1, $status, WNOHANG);

            if ($pid > 0) {
                $child = $this->children[$pid] ?? null;

                if ($child instanceof Child) {
                    $this->closeChildStreams($child);
                    unset($this->children[$pid]);
                }
            } else {
                usleep(100_000);
            }
        }

        // Force-kill any remaining
        foreach ($this->children as $child) {
            posix_kill($child->pid, SIGKILL);
            pcntl_waitpid($child->pid, $status, 0);
            $this->closeChildStreams($child);
        }

        $this->children = [];
    }

    protected function fireOnChildCreate(Child $child): void
    {
        if ($this->onChildCreateCallback instanceof \Closure) {
            ($this->onChildCreateCallback)($child, $child->createReason);
        }
    }

    protected function findChildByStream(mixed $stream): ?Child
    {
        return array_find($this->children, fn ($child) => in_array($stream, [$child->stdout, $child->stderr, $child->ipcChannel], true));
    }
}
