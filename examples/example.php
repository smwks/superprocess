<?php

/**
 * SuperProcess scratch examples
 *
 * Run from the project root:
 *   php scratch/example.php
 *
 * Requires: PHP 8.4+, ext-pcntl, ext-posix  (Linux / macOS only)
 *
 * Two examples are included below. Both are active; they run sequentially.
 * Comment out whichever you don't want to run.
 */

require __DIR__.'/../vendor/autoload.php';

use SMWks\SuperProcess\Child;
use SMWks\SuperProcess\CreateReason;
use SMWks\SuperProcess\ExitReason;
use SMWks\SuperProcess\ProcessSignal;
use SMWks\SuperProcess\SuperProcess;

// =============================================================================
// EXAMPLE 1 — Command supervisor
//
// Runs 2 copies of a short-lived PHP worker. Each worker ticks every second
// for 4 seconds then exits cleanly. The supervisor automatically replaces each
// exiting worker to keep exactly 2 running. After 15 seconds the heartbeat
// callback tells the master to shut down.
//
// Demonstrates: command(), scaleLimits(), onChildCreate(), onChildOutput(),
//               onChildExit(), heartbeat(), ProcessSignal
// =============================================================================

echo str_repeat('=', 70)."\n";
echo "EXAMPLE 1: command supervisor\n";
echo str_repeat('=', 70)."\n\n";

$startTime = time();

// Inline PHP script that ticks 4 times then exits.
$workerScript = <<<'PHP'
    $pid = getmypid();
    for ($i = 1; $i <= 4; $i++) {
        echo "[$pid] tick $i/4\n";
        sleep(1);
    }
    echo "[$pid] done — exiting normally\n";
PHP;

$sp = new SuperProcess;
$sp->command('php -r '.escapeshellarg($workerScript))
    ->scaleLimits(min: 2, max: 4)

    ->onChildCreate(function (Child $child, CreateReason $reason): void {
        $label = match ($reason) {
            CreateReason::Initial => 'initial start',
            CreateReason::Replacement => 'replacement after exit',
            CreateReason::ScaleUp => 'scale-up request',
        };
        echo "[master] spawned pid {$child->pid}  ({$label})\n";
    })

    ->onChildOutput(function (Child $child, string $data): void {
        // stdout and stderr both arrive here; trim trailing newlines for tidy output
        foreach (explode("\n", trim($data)) as $line) {
            if ($line !== '') {
                echo "[output] {$line}\n";
            }
        }
    })

    ->onChildExit(function (Child $child, ExitReason $reason): void {
        $detail = match ($reason) {
            ExitReason::Normal => "exit code {$child->exitCode}",
            ExitReason::Signal => 'killed by signal',
            ExitReason::Killed => 'force-killed (SIGKILL)',
            ExitReason::Unknown => 'unknown',
        };
        echo "[master] pid {$child->pid} exited — {$detail}\n";
    })

    // Every 5 seconds print elapsed time; stop the supervisor after 15 seconds.
    ->heartbeat(5, function (SuperProcess $super) use ($startTime): void {
        $elapsed = time() - $startTime;
        echo "[heartbeat] {$elapsed}s elapsed\n";

        if ($elapsed >= 15) {
            echo "[heartbeat] time limit reached — sending STOP\n";
            $super->signal(posix_getpid(), ProcessSignal::Stop);
        }
    })

    ->run();

echo "\n[master] example 1 done\n\n";
