<?php

use SMWks\SuperProcess\Child;
use SMWks\SuperProcess\CreateReason;
use SMWks\SuperProcess\Exceptions\ProcessException;
use SMWks\SuperProcess\ExitReason;
use SMWks\SuperProcess\ProcessSignal;
use SMWks\SuperProcess\SuperProcess;

// ---------------------------------------------------------------------------
// ProcessSignal constants
// ---------------------------------------------------------------------------

it('defines expected signal values', function (): void {
    expect(ProcessSignal::Stop->signum())->toBe(SIGTERM)
        ->and(ProcessSignal::Kill->signum())->toBe(SIGKILL)
        ->and(ProcessSignal::Reload->signum())->toBe(SIGHUP)
        ->and(ProcessSignal::Usr1->signum())->toBe(SIGUSR1)
        ->and(ProcessSignal::Usr2->signum())->toBe(SIGUSR2);
});

// ---------------------------------------------------------------------------
// CreateReason enum
// ---------------------------------------------------------------------------

it('has the expected CreateReason cases', function (): void {
    expect(CreateReason::Initial->name)->toBe('Initial')
        ->and(CreateReason::Replacement->name)->toBe('Replacement')
        ->and(CreateReason::ScaleUp->name)->toBe('ScaleUp');
});

// ---------------------------------------------------------------------------
// ExitReason enum
// ---------------------------------------------------------------------------

it('has the expected ExitReason cases', function (): void {
    expect(ExitReason::Normal->name)->toBe('Normal')
        ->and(ExitReason::Signal->name)->toBe('Signal')
        ->and(ExitReason::Killed->name)->toBe('Killed')
        ->and(ExitReason::Unknown->name)->toBe('Unknown');
});

// ---------------------------------------------------------------------------
// Child value object
// ---------------------------------------------------------------------------

it('constructs a Child with correct defaults', function (): void {
    $child = new Child(
        pid: 1234,
        createReason: CreateReason::Initial,
        processHandle: null,
        stdin: null,
        stdout: null,
        stderr: null,
        ipcChannel: null,
    );

    expect($child->pid)->toBe(1234)
        ->and($child->createReason)->toBe(CreateReason::Initial)
        ->and($child->running)->toBeTrue()
        ->and($child->exitCode)->toBe(0)
        ->and($child->exitReason)->toBe(ExitReason::Unknown);
});

it('returns empty readable streams when all are null', function (): void {
    $child = new Child(
        pid: 1,
        createReason: CreateReason::Initial,
        processHandle: null,
        stdin: null,
        stdout: null,
        stderr: null,
        ipcChannel: null,
    );

    expect($child->readableStreams())->toBe([]);
});

it('returns only non-null readable streams', function (): void {
    $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    assert($pair !== false);

    [$a, $b] = $pair;

    $child = new Child(
        pid: 1,
        createReason: CreateReason::Initial,
        processHandle: null,
        stdin: null,
        stdout: $a,
        stderr: null,
        ipcChannel: $b,
    );

    expect($child->readableStreams())->toHaveCount(2);

    fclose($a);
    fclose($b);
});

// ---------------------------------------------------------------------------
// SuperProcess – configuration
// ---------------------------------------------------------------------------

it('throws ProcessException when run() called without a command or closure', function (): void {
    $sp = new SuperProcess;
    $sp->run();
})->throws(ProcessException::class);

it('returns static from fluent configuration methods', function (): void {
    $sp = new SuperProcess;

    $noop = function (): void {};

    expect($sp->command('echo hello'))->toBeInstanceOf(SuperProcess::class)
        ->and($sp->scaleLimits(1, 3))->toBeInstanceOf(SuperProcess::class)
        ->and($sp->heartbeat(30, $noop))->toBeInstanceOf(SuperProcess::class)
        ->and($sp->onChildCreate($noop))->toBeInstanceOf(SuperProcess::class)
        ->and($sp->onChildExit($noop))->toBeInstanceOf(SuperProcess::class)
        ->and($sp->onChildSignal($noop))->toBeInstanceOf(SuperProcess::class)
        ->and($sp->onChildMessage($noop))->toBeInstanceOf(SuperProcess::class)
        ->and($sp->onChildOutput($noop))->toBeInstanceOf(SuperProcess::class)
        ->and($sp->closure($noop))->toBeInstanceOf(SuperProcess::class);
});

// ---------------------------------------------------------------------------
// SuperProcess – command child lifecycle
// ---------------------------------------------------------------------------

it('spawns a command child, fires onChildCreate, then exits when SIGTERM sent', function (): void {
    $created = [];
    $exited = [];

    $sp = new SuperProcess;
    $sp->command('sleep 10')
        ->scaleLimits(1, 1)
        ->onChildCreate(function (Child $child, CreateReason $reason) use (&$created): void {
            $created[] = ['pid' => $child->pid, 'reason' => $reason];
        })
        ->onChildExit(function (Child $child, ExitReason $reason) use (&$exited, $sp): void {
            $exited[] = ['pid' => $child->pid, 'reason' => $reason];
            // After the child exits, stop the loop
            $sp->signal(posix_getpid(), ProcessSignal::Stop);
        });

    $sp->run();

    expect($created)->toHaveCount(2) // 1 initial + 1 replacement (because we kill it)
        ->and($exited)->toHaveCount(1)
        ->and($exited[0]['reason'])->toBe(ExitReason::Signal);
})->skip('Integration test requires pcntl/posix and spawns real processes');

// ---------------------------------------------------------------------------
// SuperProcess – closure child lifecycle
// ---------------------------------------------------------------------------

it('spawns a closure child and receives an ipc message', function (): void {
    $messages = [];

    $sp = new SuperProcess;
    $sp->closure(function (mixed $socket): void {
        fwrite($socket, json_encode(['hello' => 'world'])."\n");
        fclose($socket);
    })
        ->scaleLimits(1, 1)
        ->onChildMessage(function (Child $child, mixed $message) use (&$messages, $sp): void {
            $messages[] = $message;
            $sp->signal(posix_getpid(), ProcessSignal::Stop);
        })
        ->run();

    expect($messages)->toHaveCount(1)
        ->and($messages[0])->toBe(['hello' => 'world']);
})->skip('Integration test requires pcntl/posix and spawns real processes');

// ---------------------------------------------------------------------------
// SuperProcess – scaling
// ---------------------------------------------------------------------------

it('scaleUp spawns an additional child with ScaleUp create reason', function (): void {
    $createReasons = [];

    $sp = new SuperProcess;
    $sp->closure(function (mixed $socket): void {
        sleep(60);
    })
        ->scaleLimits(min: 1, max: 2)
        ->onChildCreate(function (Child $child, CreateReason $reason) use ($sp, &$createReasons): void {
            $createReasons[] = $reason;

            if ($reason === CreateReason::Initial) {
                $sp->scaleUp();
            }

            if (count($createReasons) === 2) {
                $sp->signal(posix_getpid(), ProcessSignal::Stop);
            }
        })
        ->run();

    expect($createReasons)->toBe([CreateReason::Initial, CreateReason::ScaleUp]);
})->skip(! extension_loaded('pcntl'), 'Requires ext-pcntl and ext-posix');

it('scaleDown called twice terminates two different children', function (): void {
    $exitedPids = [];
    $childCount = 0;
    $scaledDown = false;

    $sp = new SuperProcess;
    $sp->closure(function (mixed $socket): void {
        sleep(60);
    })
        ->scaleLimits(min: 1, max: 3)
        ->onChildCreate(function (Child $child, CreateReason $reason) use ($sp, &$childCount, &$scaledDown): void {
            $childCount++;

            // On the first child, immediately scale up to fill the pool to 3
            if ($childCount === 1) {
                $sp->scaleUp()->scaleUp();
            }

            // Once all 3 are up, scale back down by 2 in a single synchronous burst
            if ($childCount === 3 && ! $scaledDown) {
                $scaledDown = true;
                $sp->scaleDown()->scaleDown();
            }
        })
        ->onChildExit(function (Child $child, ExitReason $reason) use ($sp, &$exitedPids): void {
            $exitedPids[] = $child->pid;

            if (count($exitedPids) === 2) {
                $sp->signal(posix_getpid(), ProcessSignal::Stop);
            }
        })
        ->run();

    expect($exitedPids)->toHaveCount(2)
        ->and(array_unique($exitedPids))->toHaveCount(2); // two distinct PIDs, not the same one twice
})->skip(! extension_loaded('pcntl'), 'Requires ext-pcntl and ext-posix');
