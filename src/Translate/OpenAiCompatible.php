<?php

declare(strict_types=1);

namespace Metaglot\Translate;

use Metaglot\Config;
use Metaglot\Http;
use RuntimeException;

/**
 * Talks to any OpenAI-compatible chat completions endpoint: a local Ollama
 * instance, OpenAI itself, or anything else speaking the same API.
 */
final class OpenAiCompatible implements TranslatorInterface
{
    public function __construct(
        private readonly Http $http,
        private readonly PromptBuilder $prompts,
        private readonly Config $config,
    ) {
    }

    public function localize(string $title, string $description, string $sourceLang, array $targetLangs): array
    {
        [$status, $res] = $this->http->request(
            'POST',
            $this->config->llmEndpoint,
            array_filter([
                'Content-Type: application/json',
                $this->config->llmKey !== '' ? 'Authorization: Bearer ' . $this->config->llmKey : null,
            ]),
            (string) json_encode([
                'model'           => $this->config->llmModel,
                'temperature'     => 0.3,
                'response_format' => ['type' => 'json_object'],
                'messages'        => [
                    ['role' => 'system', 'content' => $this->prompts->systemPrompt()],
                    [
                        'role'    => 'user',
                        'content' => $this->prompts->userPayload($title, $description, $sourceLang, $targetLangs),
                    ],
                ],
            ], JSON_UNESCAPED_UNICODE),
        );

        if ($status !== 200) {
            throw new RuntimeException("LLM request failed ($status): " . (string) json_encode($res));
        }

        $content = $res['choices'][0]['message']['content'] ?? '';
        $content = trim((string) preg_replace('/^```(?:json)?|```$/m', '', (string) $content));
        $parsed  = json_decode($content, true);

        if (!is_array($parsed)) {
            throw new RuntimeException('LLM did not return valid JSON.');
        }

        // Enforce the limits here — YouTube rejects titles over 100 characters.
        $clean = [];
        foreach ($targetLangs as $lang) {
            if (empty($parsed[$lang]['title'])) {
                continue;
            }
            $clean[$lang] = [
                'title'       => mb_substr((string) $parsed[$lang]['title'], 0, self::TITLE_MAX),
                'description' => mb_substr((string) ($parsed[$lang]['description'] ?? $description), 0, self::DESC_MAX),
            ];
        }

        return $clean;
    }
}
