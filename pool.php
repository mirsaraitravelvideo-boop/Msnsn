<?php
// pool.php — Worker Pool (Multi-Bot)
declare(strict_types=1);
@ini_set('memory_limit', '-1');
@ini_set('max_execution_time', '0');
error_reporting(E_ALL & ~E_WARNING & ~E_NOTICE);
date_default_timezone_set('Asia/Dhaka');
set_time_limit(0);
ignore_user_abort(true);

if (function_exists('posix_setrlimit')) {
    @posix_setrlimit(POSIX_RLIMIT_NOFILE, 1048576, 1048576);
}

const MIN_WORKERS = 8;       // আপনার ৮টি বট → ৮টি worker
const MAX_WORKERS = 24;      // auto-scale max
const WORKER_PID_DIR = __DIR__ . "/pids/w";
const QUEUE_DIR = __DIR__ . "/queue";
const LOG_DIR = __DIR__ . "/logs";

@mkdir(WORKER_PID_DIR, 0777, true);
@mkdir(QUEUE_DIR, 0777, true);
@mkdir(LOG_DIR, 0777, true);
@file_put_contents(__DIR__ . "/pids/pool.pid", (string)getmypid(), LOCK_EX);

function pLog($m) {
    @file_put_contents(LOG_DIR . "/pool.log", "[" . date('H:i:s') . "] $m\n", FILE_APPEND);
    echo "[" . date('H:i:s') . "] $m\n";
}

function spawnW($id) {
    $f = __DIR__ . "/worker.php";
    $log = LOG_DIR . "/worker_$id.log";
    @exec("php " . escapeshellarg($f) . " $id > " . escapeshellarg($log) . " 2>&1 &");
    usleep(50000);
    @exec("pgrep -f 'worker.php $id' | head -1", $out);
    if (!empty($out[0])) {
        @file_put_contents(WORKER_PID_DIR . "/$id.pid", trim($out[0]), LOCK_EX);
    }
    pLog("Spawned worker #$id");
}

function wAlive($id) {
    $f = WORKER_PID_DIR . "/$id.pid";
    if (!file_exists($f)) return false;
    $pid = (int) @file_get_contents($f);
    return $pid > 0 && function_exists('posix_kill') ? @posix_kill($pid, 0) : true;
}

pLog("Pool started — Workers: " . MIN_WORKERS . " → " . MAX_WORKERS);

for ($i = 1; $i <= MIN_WORKERS; $i++) spawnW($i);

$last = 0;
while (true) {
    if (time() - $last >= 3) {
        $last = time();

        // Dead restart
        for ($i = 1; $i <= MAX_WORKERS; $i++) {
            if (file_exists(WORKER_PID_DIR . "/$i.pid") && !wAlive($i)) {
                pLog("Restart worker #$i");
                spawnW($i);
            }
        }

        // Auto-scale
        $q = count(glob(QUEUE_DIR . "/job_*.json"));
        $need = min(MAX_WORKERS, MIN_WORKERS + (int)ceil($q / 3));
        for ($i = MIN_WORKERS + 1; $i <= $need; $i++) {
            if (!file_exists(WORKER_PID_DIR . "/$i.pid") || !wAlive($i)) {
                spawnW($i);
            }
        }
    }

    if (function_exists('pcntl_waitpid')) {
        while (($pid = @pcntl_waitpid(-1, $status, WNOHANG)) > 0) { }
    }
    usleep(10000);
}