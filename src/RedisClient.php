<?php

declare(strict_types=1);

namespace Azanumer\Redis;

/**
 * Dependency-free minimal Redis client speaking the RESP protocol directly
 * over a TCP (or Unix) socket. No extension, no library — plain PHP.
 *
 * Supported commands: PING, AUTH, SELECT, GET, SET (+EX/PX/NX/XX/KEEPTTL),
 * SETEX, SETNX, MGET, MSET, DEL, EXISTS, INCR, INCRBY, DECR, EXPIRE, TTL,
 * PERSIST, HSET, HGET, HGETALL, HDEL, HEXISTS, LPUSH, RPUSH, LPOP, RPOP,
 * LLEN, LRANGE, SADD, SREM, SMEMBERS, SISMEMBER, PUBLISH, FLUSHDB, INFO,
 * plus a generic command() escape hatch and pipeline().
 *
 * Example:
 *   $redis = new RedisClient('127.0.0.1', 6379);
 *   $redis->set('greeting', 'hello', 3600);
 *   echo $redis->get('greeting'); // hello
 */
class RedisClient
{
    /** @var resource|null */
    private $socket;

    /** @var string */
    private $host;

    /** @var int */
    private $port;

    /** @var float */
    private $timeout;

    /** @var string|null */
    private $password;

    /** @var int */
    private $database;

    /** @var string|null Unix socket path — when set, host/port are ignored. */
    private $unixSocket;

    public function __construct(
        string $host = '127.0.0.1',
        int $port = 6379,
        float $timeout = 2.5,
        ?string $password = null,
        int $database = 0,
        ?string $unixSocket = null
    ) {
        $this->host       = $host;
        $this->port       = $port;
        $this->timeout    = $timeout;
        $this->password   = $password;
        $this->database   = $database;
        $this->unixSocket = $unixSocket;
    }

    /* ------------------------------------------------------------------ */
    /* Connection                                                          */
    /* ------------------------------------------------------------------ */

    public function connect(): void
    {
        if (is_resource($this->socket)) {
            return;
        }

        $address = $this->unixSocket !== null
            ? 'unix://' . $this->unixSocket
            : 'tcp://' . $this->host . ':' . $this->port;

        $errno  = 0;
        $errstr = '';
        $socket = @stream_socket_client($address, $errno, $errstr, $this->timeout);
        if ($socket === false) {
            throw new RedisException(sprintf('Could not connect to Redis at %s: %s', $address, $errstr));
        }

        stream_set_timeout($socket, (int) $this->timeout, (int) (($this->timeout - (int) $this->timeout) * 1000000));
        $this->socket = $socket;

        if ($this->password !== null) {
            $this->auth($this->password);
        }
        if ($this->database !== 0) {
            $this->select($this->database);
        }
    }

    public function close(): void
    {
        if (is_resource($this->socket)) {
            fclose($this->socket);
            $this->socket = null;
        }
    }

    public function isConnected(): bool
    {
        return is_resource($this->socket);
    }

    public function __destruct()
    {
        $this->close();
    }

    /* ------------------------------------------------------------------ */
    /* RESP wire protocol                                                  */
    /* ------------------------------------------------------------------ */

    /**
     * Send one command and return its reply.
     *
     * @param mixed ...$args Command name followed by its arguments.
     * @return mixed string|int|array|null
     */
    public function command(string $name, ...$args)
    {
        $this->connect();
        $this->writeCommand(array_merge([$name], $args));
        return $this->readReply();
    }

    /**
     * Send several commands at once and return their replies in order.
     *
     * @param array<int, array<int, mixed>> $commands Each item: [name, arg, ...].
     * @return array<int, mixed>
     */
    public function pipeline(array $commands): array
    {
        $this->connect();
        foreach ($commands as $command) {
            $this->writeCommand($command);
        }
        $replies = [];
        foreach ($commands as $command) {
            $replies[] = $this->readReply();
        }
        return $replies;
    }

    /**
     * Encode a command as a RESP array and write it to the socket.
     */
    private function writeCommand(array $args): void
    {
        $parts = ['*' . count($args) . "\r\n"];
        foreach ($args as $arg) {
            $arg     = (string) $arg;
            $parts[] = '$' . strlen($arg) . "\r\n" . $arg . "\r\n";
        }
        $payload = implode('', $parts);
        $written = fwrite($this->socket, $payload);
        if ($written === false || $written !== strlen($payload)) {
            throw new RedisException('Failed to write command to Redis socket.');
        }
    }

    /**
     * Read and parse one RESP reply from the socket.
     *
     * @return mixed string|int|array|null
     */
    private function readReply()
    {
        $line = $this->readLine();
        if ($line === '') {
            throw new RedisException('Empty reply from Redis (connection closed?).');
        }

        $type    = $line[0];
        $payload = substr($line, 1);

        switch ($type) {
            case '+': // Simple string.
                return $payload;

            case '-': // Error.
                throw new RedisException('Redis error: ' . $payload);

            case ':': // Integer.
                return (int) $payload;

            case '$': // Bulk string (or nil for $-1).
                $length = (int) $payload;
                if ($length === -1) {
                    return null;
                }
                $data = '';
                $remaining = $length + 2; // Payload + trailing CRLF.
                while ($remaining > 0) {
                    $chunk = fread($this->socket, $remaining);
                    if ($chunk === false || $chunk === '') {
                        throw new RedisException('Connection closed while reading bulk string.');
                    }
                    $data .= $chunk;
                    $remaining -= strlen($chunk);
                }
                return substr($data, 0, $length);

            case '*': // Array (or nil for *-1).
                $count = (int) $payload;
                if ($count === -1) {
                    return null;
                }
                $items = [];
                for ($i = 0; $i < $count; $i++) {
                    $items[] = $this->readReply();
                }
                return $items;

            default:
                throw new RedisException('Unknown RESP reply type: ' . $type);
        }
    }

    private function readLine(): string
    {
        $line = fgets($this->socket, 4096);
        if ($line === false) {
            throw new RedisException('Connection closed while reading reply.');
        }
        return rtrim($line, "\r\n");
    }

    /* ------------------------------------------------------------------ */
    /* Connection commands                                                 */
    /* ------------------------------------------------------------------ */

    public function ping(?string $message = null)
    {
        return $message === null
            ? $this->command('PING')
            : $this->command('PING', $message);
    }

    public function auth(string $password, ?string $username = null)
    {
        return $username === null
            ? $this->command('AUTH', $password)
            : $this->command('AUTH', $username, $password);
    }

    public function select(int $index)
    {
        return $this->command('SELECT', $index);
    }

    /* ------------------------------------------------------------------ */
    /* String commands                                                     */
    /* ------------------------------------------------------------------ */

    /**
     * @param int|null    $ttlSeconds Expire after N seconds (EX), null = keep.
     * @param string|null $mode       'NX' (only if not exists) or 'XX' (only if exists).
     * @return string|bool|null 'OK' on success, null when NX/XX condition fails.
     */
    public function set(string $key, string $value, ?int $ttlSeconds = null, ?string $mode = null)
    {
        $args = ['SET', $key, $value];
        if ($ttlSeconds !== null) {
            $args[] = 'EX';
            $args[] = $ttlSeconds;
        }
        if ($mode !== null) {
            $args[] = strtoupper($mode);
        }
        return $this->command(...$args);
    }

    public function get(string $key): ?string
    {
        return $this->command('GET', $key);
    }

    public function setex(string $key, int $ttlSeconds, string $value)
    {
        return $this->command('SETEX', $key, $ttlSeconds, $value);
    }

    /** @return int 1 if the key was set, 0 if it already existed. */
    public function setnx(string $key, string $value): int
    {
        return (int) $this->command('SETNX', $key, $value);
    }

    /** @param string[] $keys @return array<int, string|null> */
    public function mget(array $keys): array
    {
        return $this->command('MGET', ...$keys);
    }

    /** @param array<string, string> $pairs */
    public function mset(array $pairs)
    {
        $args = ['MSET'];
        foreach ($pairs as $key => $value) {
            $args[] = $key;
            $args[] = $value;
        }
        return $this->command(...$args);
    }

    /** @return int Number of keys deleted. */
    public function del(string ...$keys): int
    {
        return (int) $this->command('DEL', ...$keys);
    }

    /** @return int Number of the given keys that exist. */
    public function exists(string ...$keys): int
    {
        return (int) $this->command('EXISTS', ...$keys);
    }

    public function incr(string $key): int
    {
        return (int) $this->command('INCR', $key);
    }

    public function incrBy(string $key, int $amount): int
    {
        return (int) $this->command('INCRBY', $key, $amount);
    }

    public function decr(string $key): int
    {
        return (int) $this->command('DECR', $key);
    }

    /** @return int 1 if the timeout was set, 0 if the key does not exist. */
    public function expire(string $key, int $ttlSeconds): int
    {
        return (int) $this->command('EXPIRE', $key, $ttlSeconds);
    }

    /** @return int TTL in seconds, -1 = no expiry, -2 = key does not exist. */
    public function ttl(string $key): int
    {
        return (int) $this->command('TTL', $key);
    }

    /** @return int 1 if the timeout was removed, 0 if the key has none / missing. */
    public function persist(string $key): int
    {
        return (int) $this->command('PERSIST', $key);
    }

    /* ------------------------------------------------------------------ */
    /* Hash commands                                                       */
    /* ------------------------------------------------------------------ */

    /** @return int 1 if a new field was created, 0 if an existing field was updated. */
    public function hset(string $key, string $field, string $value): int
    {
        return (int) $this->command('HSET', $key, $field, $value);
    }

    public function hget(string $key, string $field): ?string
    {
        return $this->command('HGET', $key, $field);
    }

    /** @return array<string, string> */
    public function hgetall(string $key): array
    {
        $flat = $this->command('HGETALL', $key);
        $out  = [];
        for ($i = 0; $i < count($flat); $i += 2) {
            $out[$flat[$i]] = $flat[$i + 1];
        }
        return $out;
    }

    /** @return int Number of fields removed. */
    public function hdel(string $key, string ...$fields): int
    {
        return (int) $this->command('HDEL', $key, ...$fields);
    }

    /** @return int 1 if the field exists, 0 otherwise. */
    public function hexists(string $key, string $field): int
    {
        return (int) $this->command('HEXISTS', $key, $field);
    }

    /* ------------------------------------------------------------------ */
    /* List commands                                                       */
    /* ------------------------------------------------------------------ */

    /** @return int Length of the list after the push. */
    public function lpush(string $key, string ...$values): int
    {
        return (int) $this->command('LPUSH', $key, ...$values);
    }

    /** @return int Length of the list after the push. */
    public function rpush(string $key, string ...$values): int
    {
        return (int) $this->command('RPUSH', $key, ...$values);
    }

    public function lpop(string $key): ?string
    {
        return $this->command('LPOP', $key);
    }

    public function rpop(string $key): ?string
    {
        return $this->command('RPOP', $key);
    }

    public function llen(string $key): int
    {
        return (int) $this->command('LLEN', $key);
    }

    /** @return array<int, string|null> */
    public function lrange(string $key, int $start, int $stop): array
    {
        return $this->command('LRANGE', $key, $start, $stop);
    }

    /* ------------------------------------------------------------------ */
    /* Set commands                                                        */
    /* ------------------------------------------------------------------ */

    /** @return int Number of members added. */
    public function sadd(string $key, string ...$members): int
    {
        return (int) $this->command('SADD', $key, ...$members);
    }

    /** @return int Number of members removed. */
    public function srem(string $key, string ...$members): int
    {
        return (int) $this->command('SREM', $key, ...$members);
    }

    /** @return string[] */
    public function smembers(string $key): array
    {
        return $this->command('SMEMBERS', $key);
    }

    /** @return int 1 if the member exists, 0 otherwise. */
    public function sismember(string $key, string $member): int
    {
        return (int) $this->command('SISMEMBER', $key, $member);
    }

    /* ------------------------------------------------------------------ */
    /* Pub/Sub + server                                                    */
    /* ------------------------------------------------------------------ */

    /** @return int Number of clients that received the message. */
    public function publish(string $channel, string $message): int
    {
        return (int) $this->command('PUBLISH', $channel, $message);
    }

    public function flushDb()
    {
        return $this->command('FLUSHDB');
    }

    /**
     * INFO output as a key => value array (section headers skipped).
     *
     * @return array<string, string>
     */
    public function info(?string $section = null): array
    {
        $raw = $section === null
            ? $this->command('INFO')
            : $this->command('INFO', $section);

        $out = [];
        foreach (explode("\n", (string) $raw) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') {
                continue;
            }
            $pos = strpos($line, ':');
            if ($pos !== false) {
                $out[substr($line, 0, $pos)] = substr($line, $pos + 1);
            }
        }
        return $out;
    }
}
