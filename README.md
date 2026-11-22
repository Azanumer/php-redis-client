# php-redis-client

A dependency-free minimal Redis client for PHP — it speaks the RESP protocol directly over a TCP (or Unix) socket. No `phpredis` extension, no Predis, no Composer: just two files you can drop into any project.

## Usage

```php
require 'src/RedisException.php';
require 'src/RedisClient.php';

use Azanumer\Redis\RedisClient;

$redis = new RedisClient('127.0.0.1', 6379);

$redis->set('greeting', 'hello', 3600);   // with 1h TTL
echo $redis->get('greeting');             // hello

$redis->incr('page:views');
$redis->hset('user:42', 'name', 'Azan');
$redis->rpush('jobs', 'job-1', 'job-2');
$redis->sadd('tags', 'php', 'redis');
```

### Constructor

```php
new RedisClient(
    string $host = '127.0.0.1',
    int $port = 6379,
    float $timeout = 2.5,
    ?string $password = null,   // AUTH on connect
    int $database = 0,          // SELECT on connect
    ?string $unixSocket = null  // e.g. '/run/redis/redis.sock' — overrides host/port
);
```

The connection is lazy — it opens on the first command.

### Supported commands

| Group | Methods |
|---|---|
| Connection | `ping()`, `auth()`, `select()`, `close()`, `isConnected()` |
| Strings | `set()` (+EX TTL, NX/XX modes), `get()`, `setex()`, `setnx()`, `mget()`, `mset()`, `del()`, `exists()`, `incr()`, `incrBy()`, `decr()`, `expire()`, `ttl()`, `persist()` |
| Hashes | `hset()`, `hget()`, `hgetall()` (returns assoc array), `hdel()`, `hexists()` |
| Lists | `lpush()`, `rpush()`, `lpop()`, `rpop()`, `llen()`, `lrange()` |
| Sets | `sadd()`, `srem()`, `smembers()`, `sismember()` |
| Pub/Sub + server | `publish()`, `flushDb()`, `info()` (parsed into key ⇒ value) |

Plus a generic escape hatch and batching:

```php
$redis->command('SET', 'k', 'v', 'EX', 60);      // any command, raw reply

[$pong, $val, $len] = $redis->pipeline([           // 1 round trip
    ['PING'],
    ['GET', 'greeting'],
    ['LLEN', 'jobs'],
]);
```

## Errors

Server error replies (`-ERR ...`) and connection failures throw `Azanumer\Redis\RedisException` (extends `RuntimeException`). Missing keys return `null` (`GET`, `HGET`, `LPOP`) — never an exception.

## Example

See [`examples/redis-example.php`](examples/redis-example.php) — run it against a local server (`docker run -p 6379:6379 redis:7-alpine`).

## Requirements

- PHP 7.4+
- A Redis server (2.6+ for the commands used here; `HSET` single-field form works on all versions)

## License

MIT — see [LICENSE](LICENSE).
