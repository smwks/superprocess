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
use SMWks\SuperProcess\ProcessSignal;
use SMWks\SuperProcess\SuperProcess;

// =============================================================================
// EXAMPLE 3 — Signal handling and graceful shutdown
//
// Forks a single worker that runs an infinite loop. The master listens for
// SIGINT (Ctrl+C) and SIGTERM, and when received it signals the worker to
// shut down gracefully. The worker listens for the shutdown signal, does some
// cleanup work, then exits. The master waits for the worker to exit before
// shutting down itself.
//
// Demonstrates: onSignal(), ProcessSignal, graceful shutdown
// =============================================================================

echo str_repeat('=', 70)."\n";
echo "EXAMPLE 3: signal handling and graceful shutdown\n";
echo str_repeat('=', 70)."\n\n";
echo 'PID: ';
echo posix_getpid();
echo str_repeat('=', 70)."\n\n";

$cwd = getcwd();

$sp3 = new SuperProcess;
$sp3
    ->command("php $cwd/examples/scripts/hello-world-time.php")
    ->scaleLimits(2, 2)
    ->heartbeat(10, function (SuperProcess $super): void {
        echo "[heartbeat 10s]\n";
    })
    ->onChildOutput(
        function (Child $child, string $output): void {
            echo "[child {$child->pid}] $output";
        }
    )
    ->run();
