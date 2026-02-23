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
// EXAMPLE 2 — Closure workers with structured IPC messages
//
// Forks 3 worker closures. Each closure sends progress updates over the IPC
// socket, then a final "done" message with a result. The master collects all
// results and shuts down once every worker has sent its "done" message.
//
// Demonstrates: closure(), onChildMessage(), onChildCreate(), onChildExit(),
//               scaleLimits(), structured JSON IPC protocol
// =============================================================================

echo str_repeat('=', 70)."\n";
echo "EXAMPLE 2: closure workers with IPC messages\n";
echo str_repeat('=', 70)."\n\n";

$results = [];
$workerCount = 3;

$sp2 = new SuperProcess;
$sp2->closure(function (mixed $socket): void {
    // Each worker has a randomly seeded workload so outputs differ.
    $pid = getmypid();
    $steps = random_int(2, 5);

    for ($i = 1; $i <= $steps; $i++) {
        fwrite($socket, json_encode([
            'type' => 'progress',
            'pid' => $pid,
            'step' => $i,
            'of' => $steps,
        ])."\n");
        sleep(1);
    }

    fwrite($socket, json_encode([
        'type' => 'done',
        'pid' => $pid,
        'result' => $steps * 100,   // pretend result value
    ])."\n");
})
    ->scaleLimits(min: $workerCount, max: $workerCount)

    ->onChildCreate(function (Child $child, CreateReason $reason): void {
        echo "[master] worker {$child->pid} started\n";
    })

    ->onChildMessage(function (Child $child, mixed $msg) use ($sp2, $workerCount, &$results): void {
        if ($msg['type'] === 'progress') {
            echo "[worker {$msg['pid']}] step {$msg['step']}/{$msg['of']}\n";
        } elseif ($msg['type'] === 'done') {
            echo "[worker {$msg['pid']}] done — result: {$msg['result']}\n";
            $results[$msg['pid']] = $msg['result'];

            // Once all workers have reported, stop the supervisor.
            if (count($results) === $workerCount) {
                echo "[master] all workers done — shutting down\n";
                $sp2->signal(posix_getpid(), ProcessSignal::Stop);
            }
        }
    })

    ->onChildExit(function (Child $child, ExitReason $reason): void {
        echo "[master] worker {$child->pid} exited\n";
    })

    ->run();

echo "\n[master] collected results:\n";
foreach ($results as $pid => $value) {
    echo "  pid {$pid} => {$value}\n";
}
echo "\n[master] example 2 done\n";
