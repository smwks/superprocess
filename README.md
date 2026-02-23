# SuperProcess

A fluent PHP library for supervised master-child process control using `pcntl` and pipes. Run one or more copies of a command or PHP closure, keep them alive automatically, communicate with them via stdin/stdout and a structured IPC channel, and scale the pool up or down at runtime.

> **Requires PHP 8.4+, `ext-pcntl`, `ext-posix` — Linux and macOS only.**

---

## Installation

```bash
composer require smwks/superprocess
```

---

## Quick start

```php
use SMWks\SuperProcess\Child;
use SMWks\SuperProcess\CreateReason;
use SMWks\SuperProcess\ExitReason;
use SMWks\SuperProcess\SuperProcess;

$sp = new SuperProcess;
$sp->command('php artisan inspire:loop')
   ->scaleLimits(min: 2, max: 5)
   ->onChildCreate(fn (Child $c, CreateReason $r) => printf("[master] spawned %d\n", $c->pid))
   ->onChildExit(fn (Child $c, ExitReason $r)    => printf("[master] %d exited\n",   $c->pid))
   ->onChildOutput(fn (Child $c, string $data)   => print $data)
   ->run(); // blocks until SIGTERM is received
```

---

## Concepts

### Command children

`command(string $cmd)` runs an external process for each child slot. The command is started with `proc_open()`, so it inherits `PATH` and environment variables. Each child gets four file descriptors:

| fd | direction | purpose |
|----|-----------|---------|
| 0 — stdin  | master → child | `sendChildInput()` |
| 1 — stdout | child → master | `onChildOutput()` |
| 2 — stderr | child → master | `onChildOutput()` |
| 3 — IPC    | child → master | `onChildMessage()` (JSON lines) |

All pipes are non-blocking; reads happen inside the event loop via `stream_select()`.

### Closure children

`closure(Closure $fn)` forks the master with `pcntl_fork()` and runs the closure in the child. The closure receives a socket resource as its only argument — write JSON lines to it to send structured messages to the master via `onChildMessage()`.

```php
$sp->closure(function (mixed $socket): void {
    for ($i = 1; $i <= 5; $i++) {
        fwrite($socket, json_encode(['progress' => $i * 20]) . "\n");
        sleep(1);
    }
});
```

### Child lifecycle

On startup `run()` spawns `min` children. When a child exits:

1. `onChildExit` fires with an `ExitReason`.
2. If running count drops below `min`, a replacement is spawned with `CreateReason::Replacement`.

The master never exits the event loop on its own — send it `SIGTERM` or `SIGINT` (or call `signal(posix_getpid(), ProcessSignal::Stop)` from within a callback) to trigger a graceful shutdown.

### Graceful shutdown

On `SIGTERM` or `SIGINT` (Ctrl+C) the master:

1. Runs the `onShutdown` callback, if registered, while all children are still alive.
2. Sends `SIGTERM` to every child.
3. Waits up to 5 seconds for each to exit.
4. Sends `SIGKILL` to any that remain.

The `onShutdown` callback is the right place to flush state, close connections, or send a final message to children before they are signalled:

```php
$sp->onShutdown(function (SuperProcess $sp): void {
    echo "Shutting down — waiting for workers to finish current jobs\n";
})
->run();
```

---

## API reference

### Configuration

```php
// Set the command to run in each child (mutually exclusive with closure())
->command(string $command): static

// Set a PHP closure to run in each child (mutually exclusive with command())
->closure(Closure $fn): static   // fn(resource $socket): void

// Set the min/max number of running children (default: 1, 1)
->scaleLimits(int $min, int $max): static

// Register a periodic master heartbeat
->heartbeat(int $intervalSeconds, Closure $fn): static  // fn(SuperProcess $self): void
```

### Callbacks

```php
// Called when a child is spawned
->onChildCreate(Closure $fn): static   // fn(Child $child, CreateReason $reason): void

// Called when a child exits
->onChildExit(Closure $fn): static     // fn(Child $child, ExitReason $reason): void

// Called when SIGUSR1 or SIGUSR2 is received by the master
->onChildSignal(Closure $fn): static   // fn(Child $child, int $signal): void

// Called for each JSON message received on the child's IPC channel
->onChildMessage(Closure $fn): static  // fn(Child $child, mixed $message): void

// Called with raw stdout/stderr data from a command child
->onChildOutput(Closure $fn): static   // fn(Child $child, string $data): void

// Called once on shutdown (SIGTERM or SIGINT), before children are signalled
->onShutdown(Closure $fn): static      // fn(SuperProcess $self): void
```

### Runtime control

```php
// Write to a running child's stdin
->sendChildInput(int $pid, string $data): void

// Send any POSIX signal to a PID (use ProcessSignal constants)
->signal(string|int $pid, ProcessSignal $signal): void

// Spawn one more child (if below max)
->scaleUp(): static

// Terminate one child (if above min)
->scaleDown(): static

// Start the blocking event loop
->run(): void
```

### Enums and constants

```php
// Why a child was created
CreateReason::Initial       // first spawn on run()
CreateReason::Replacement   // auto-restarted after exit
CreateReason::ScaleUp       // spawned by scaleUp()

// Why a child exited
ExitReason::Normal          // exited via exit() / end of script
ExitReason::Signal          // terminated by a signal (SIGTERM etc.)
ExitReason::Killed          // force-killed with SIGKILL
ExitReason::Unknown         // status could not be determined

// Signal shortcuts (values map to POSIX signal numbers)
ProcessSignal::Stop         // SIGTERM — graceful stop
ProcessSignal::Kill         // SIGKILL — force kill
ProcessSignal::Reload       // SIGHUP  — reload
ProcessSignal::Usr1         // SIGUSR1
ProcessSignal::Usr2         // SIGUSR2
```

### `Child` properties

```php
$child->pid            // int  — process ID
$child->createReason   // CreateReason
$child->running        // bool — false once the process has exited
$child->exitCode       // int  — exit code (populated after exit)
$child->exitReason     // ExitReason (populated after exit)
```

---

## Sending structured messages from a command child

Write newline-delimited JSON to file descriptor 3. The master delivers each parsed line to `onChildMessage`.

```php
// child-worker.php
$ipc = fopen('php://fd/3', 'w');

fwrite($ipc, json_encode(['type' => 'started', 'pid' => getmypid()]) . "\n");

// ... do work ...

fwrite($ipc, json_encode(['type' => 'done', 'items_processed' => 1234]) . "\n");
fclose($ipc);
```

```php
// supervisor
$sp->command('php child-worker.php')
   ->onChildMessage(function (Child $child, mixed $msg): void {
       echo "[{$child->pid}] {$msg['type']}\n";
   });
```

---

## Signals

| Signal received by master | Behaviour |
|---------------------------|-----------|
| `SIGTERM` | Graceful shutdown — fires `onShutdown`, then drains children |
| `SIGINT`  | Graceful shutdown — same as `SIGTERM` (handles Ctrl+C) |
| `SIGHUP`  | Forwarded to all children |
| `SIGCHLD` | Internal — triggers zombie reaping and pool replenishment |
| `SIGUSR1` | Fires `onChildSignal` for every running child |
| `SIGUSR2` | Fires `onChildSignal` for every running child |

---

## Development

```bash
composer lint          # fix code style with Pint
composer refactor      # apply Rector suggestions
composer test:lint     # check code style
composer test:types    # PHPStan (max level)
composer test:unit     # Pest unit tests
composer test          # run all checks
```

---

**SuperProcess** is open-sourced under the [MIT license](LICENSE.md).
