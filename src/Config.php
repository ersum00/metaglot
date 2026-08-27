<?php

declare(strict_types=1);

namespace Metaglot;

/**
 * Runtime configuration resolved from environment variables.
 */
final class Config
{
    public function __construct(
        public readonly string $pgDsn,
        public readonly string $pgUser,
        public readonly string $pgPass,
        public readonly string $llmEndpoint,
        public readonly string $llmModel,
        public readonly string $llmKey,
    ) {
    }

    public static function fromEnv(): self
    {
        return new self(
            pgDsn: getenv('PG_DSN') ?: 'pgsql:host=127.0.0.1;dbname=localizer',
            pgUser: getenv('PG_USER') ?: 'localizer',
            pgPass: getenv('PG_PASS') ?: '',
            llmEndpoint: getenv('LLM_ENDPOINT') ?: 'http://127.0.0.1:11434/v1/chat/completions',
            llmModel: getenv('LLM_MODEL') ?: 'qwen2.5:14b-instruct',
            llmKey: getenv('LLM_KEY') ?: '',
        );
    }
}
