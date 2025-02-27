<?php

require_once __DIR__ . '/vendor/autoload.php';

use Adapterman\Adapterman;
use Workerman\Worker;
use Illuminate\Support\Facades\Cache;

putenv('APP_RUNNING_IN_CONSOLE=false');
define('MAX_REQUEST', 6600);
define('isWEBMAN', true);

Adapterman::init();

$ncpu = substr_count((string)@file_get_contents('/proc/cpuinfo'), "\nprocessor") + 1;
$worker_count = max(4, min(16, $ncpu * 2));

$http_worker = new Worker('http://127.0.0.1:6600');
$http_worker->count = $worker_count;
$http_worker->name  = 'AdapterMan';

$http_worker->onWorkerStart = static function () {
    try {
        require __DIR__.'/start.php';
    } catch (\Throwable $e) {
        error_log("Error in start.php: " . $e->getMessage());
    }
};

$http_worker->onMessage = static function ($connection, $request) {
    static $request_count = 0;
    static $pid;

    if ($request_count == 1) {
        $pid = posix_getppid();
        try {
            Cache::forget("WEBMANPID");
            Cache::forever("WEBMANPID", $pid);
        } catch (\Throwable $e) {
            error_log("Cache error: " . $e->getMessage());
        }
    }

    try {
        $response = run();
        if (!is_string($response)) {
            throw new RuntimeException("Invalid response from run()");
        }
        $connection->send($response);
    } catch (\Throwable $e) {
        error_log("Error in run(): " . $e->getMessage());
        $connection->send("Internal Server Error");
    }

    if (++$request_count > MAX_REQUEST) {
        $connection->send("Server restarting...");
        Worker::stopAll(5);
    }
};

Worker::runAll();
