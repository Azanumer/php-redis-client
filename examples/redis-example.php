<?php

declare(strict_types=1);

require __DIR__ . '/../src/RedisException.php';
require __DIR__ . '/../src/RedisClient.php';

use Azanumer\Redis\RedisClient;
use Azanumer\Redis\RedisException;

// Usage: php examples/redis-example.php
// Needs a Redis server on 127.0.0.1:6379 (docker: docker run -p 6379:6379 redis:7-alpine).

try {
    $redis = new RedisClient('127.0.0.1', 6379);
    echo 'PING: ' . $redis->ping() . PHP_EOL;

    // Strings with TTL.
    $redis->set('demo:greeting', 'hello from php-redis-client', 3600);
    echo 'GET demo:greeting: ' . $redis->get('demo:greeting') . PHP_EOL;

    // Counters.
    $redis->set('demo:visits', '0');
    echo 'INCR demo:visits: ' . $redis->incr('demo:visits') . PHP_EOL;

    // Hashes.
    $redis->hset('demo:user:42', 'name', 'Azan');
    $redis->hset('demo:user:42', 'role', 'admin');
    echo 'HGETALL demo:user:42: ' . json_encode($redis->hgetall('demo:user:42')) . PHP_EOL;

    // Lists.
    $redis->rpush('demo:queue', 'job-1', 'job-2');
    echo 'LPOP demo:queue: ' . $redis->lpop('demo:queue') . PHP_EOL;

    // Sets.
    $redis->sadd('demo:tags', 'php', 'redis', 'hosting');
    echo 'SMEMBERS demo:tags: ' . json_encode($redis->smembers('demo:tags')) . PHP_EOL;

    // Pipeline: 3 commands, 1 round trip.
    [$pong, $val, $len] = $redis->pipeline([
        ['PING'],
        ['GET', 'demo:greeting'],
        ['LLEN', 'demo:queue'],
    ]);
    echo "Pipeline: PING={$pong}, GET={$val}, LLEN={$len}" . PHP_EOL;

    // Cleanup.
    $redis->del('demo:greeting', 'demo:visits', 'demo:user:42', 'demo:queue', 'demo:tags');
    echo 'Done.' . PHP_EOL;
} catch (RedisException $e) {
    fwrite(STDERR, 'Redis error: ' . $e->getMessage() . PHP_EOL);
    fwrite(STDERR, "Is Redis running? Try: docker run -p 6379:6379 redis:7-alpine" . PHP_EOL);
    exit(1);
}
