<?php

declare(strict_types=1);

namespace Azanumer\Redis;

/**
 * Thrown when the Redis server returns an error reply (-ERR) or the
 * connection fails.
 */
class RedisException extends \RuntimeException
{
}
