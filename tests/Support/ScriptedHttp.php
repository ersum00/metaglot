<?php

declare(strict_types=1);

namespace Metaglot\Tests\Support;

use Closure;
use Metaglot\Http;

/**
 * Http with a scripted transport — no network involved. Each send() pops the
 * next status from the script.
 */
final class ScriptedHttp extends Http
{
    public int $calls = 0;

    /**
     * @param list<int> $statuses
     */
    public function __construct(private array $statuses, ?Closure $sleep = null)
    {
        parent::__construct($sleep);
    }

    protected function send(string $method, string $url, array $headers, ?string $body): array
    {
        $this->calls++;

        return [array_shift($this->statuses) ?? 200, ['attempt' => $this->calls]];
    }
}
