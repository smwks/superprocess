<?php

/**
 * SuperProcess Example 4 — dynamic scaling
 *
 * Run from the project root:
 *   php examples/example4.php
 *
 * Requires: PHP 8.4+, ext-pcntl, ext-posix  (Linux / macOS only)
 *
 * Starts with 1 worker, scales up to 3 after 10 seconds,
 * then scales back down to 1 after 20 seconds, and stops at 30 seconds.
 *
 * Demonstrates: scaleLimits(), scaleUp(), scaleDown(), heartbeat(),
 *               onChildCreate(), onChildExit(), onChildOutput()
 */

require __DIR__.'/../vendor/autoload.php';

use SMWks\SuperProcess\Child;
use SMWks\SuperProcess\CreateReason;
use SMWks\SuperProcess\ExitReason;
use SMWks\SuperProcess\ProcessSignal;
use SMWks\SuperProcess\SuperProcess;

$cwd = getcwd();

$sp = new SuperProcess;
$sp
    ->command("php $cwd/examples/scripts/hello-world-time.php")
    ->scaleLimits(min: 1, max: 3)

    ->onChildCreate(function (Child $child, CreateReason $reason): void {
        $label = match ($reason) {
            CreateReason::Initial => 'initial start',
            CreateReason::Replacement => 'replacement',
            CreateReason::ScaleUp => 'scale-up',
        };
        echo "[master] child {$child->pid} started ({$label})\n";
    })

    ->onChildExit(function (Child $child, ExitReason $reason): void {
        $label = match ($reason) {
            ExitReason::Normal => 'normal exit',
            ExitReason::Signal => 'signal',
            ExitReason::Killed => 'killed',
            ExitReason::Unknown => 'unknown',
        };
        echo "[master] child {$child->pid} exited ({$label})\n";
    })

    ->onChildOutput(function (Child $child, string $output): void {
        echo "[child {$child->pid}] $output";
    })

    ->heartbeat(1, function (SuperProcess $super) use (&$sp): void {
        static $elapsed = 0;
        $elapsed++;

        echo "[heartbeat] {$elapsed}s\n";

        if ($elapsed === 10) {
            echo "[master] scaling up to 3 workers\n";
            $super->scaleLimits(min: 1, max: 3)->scaleUp()->scaleUp();
        }

        if ($elapsed === 20) {
            echo "[master] scaling down to 1 worker\n";
            $super->scaleLimits(min: 1, max: 3)->scaleDown()->scaleDown();
        }

        if ($elapsed === 30) {
            echo "[master] stopping\n";
            $super->signal(posix_getpid(), ProcessSignal::Stop);
        }
    })

    ->run();

echo "[master] done\n";
