<?php

declare(strict_types=1);

namespace Metaglot\Quota;

use RuntimeException;

/**
 * Thrown when an operation would exceed a channel's daily quota, or when
 * YouTube itself rejects a request over quota.
 */
final class QuotaExhaustedException extends RuntimeException
{
}
