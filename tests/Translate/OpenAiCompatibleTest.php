<?php

declare(strict_types=1);

namespace Metaglot\Tests\Translate;

use Metaglot\Config;
use Metaglot\Http;
use Metaglot\Translate\OpenAiCompatible;
use Metaglot\Translate\PromptBuilder;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class OpenAiCompatibleTest extends TestCase
{
    private function translator(string $llmContent): OpenAiCompatible
    {
        $http = $this->createMock(Http::class);
        $http->method('request')->willReturn([200, [
            'choices' => [['message' => ['content' => $llmContent]]],
        ]]);

        $config = new Config('dsn', 'user', 'pass', 'http://llm.test', 'test-model', '');

        return new OpenAiCompatible($http, new PromptBuilder(), $config);
    }

    public function testTitleAndDescriptionAreClampedToYouTubeLimits(): void
    {
        // Regression: a localized title must never exceed 100 characters and a
        // description must never exceed 5000 — YouTube rejects the update.
        $content = (string) json_encode([
            'en' => [
                'title'       => str_repeat('t', 150),
                'description' => str_repeat('d', 6000),
            ],
        ]);

        $result = $this->translator($content)->localize('Title', 'Desc', 'tr', ['en']);

        $this->assertSame(100, mb_strlen($result['en']['title']));
        $this->assertSame(5000, mb_strlen($result['en']['description']));
    }

    public function testShortValuesPassThroughUnchanged(): void
    {
        $content = (string) json_encode(['en' => ['title' => 'Short', 'description' => 'Fine']]);

        $result = $this->translator($content)->localize('Title', 'Desc', 'tr', ['en']);

        $this->assertSame('Short', $result['en']['title']);
        $this->assertSame('Fine', $result['en']['description']);
    }

    public function testBrokenJsonFromLlmRaises(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('LLM did not return valid JSON.');

        $this->translator('this is {{ not json')->localize('Title', 'Desc', 'tr', ['en']);
    }

    public function testFencedJsonIsUnwrapped(): void
    {
        // Some models wrap their answer in a markdown code fence despite
        // response_format json_object — the fence must be stripped.
        $content = "```json\n"
            . json_encode(['en' => ['title' => 'Fenced', 'description' => 'Body']])
            . "\n```";

        $result = $this->translator($content)->localize('Title', 'Desc', 'tr', ['en']);

        $this->assertSame('Fenced', $result['en']['title']);
    }

    public function testNon200ResponseRaises(): void
    {
        $http = $this->createMock(Http::class);
        $http->method('request')->willReturn([500, ['error' => 'boom']]);

        $config     = new Config('dsn', 'user', 'pass', 'http://llm.test', 'test-model', '');
        $translator = new OpenAiCompatible($http, new PromptBuilder(), $config);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('LLM request failed (500)');

        $translator->localize('Title', 'Desc', 'tr', ['en']);
    }

    public function testLanguagesTheLlmSkippedAreOmittedNotInvented(): void
    {
        // Partial answer: only what the LLM actually produced is returned;
        // the pipeline decides how to handle the missing languages.
        $content = (string) json_encode(['en' => ['title' => 'Only English', 'description' => 'D']]);

        $result = $this->translator($content)->localize('Title', 'Desc', 'tr', ['en', 'es', 'fr']);

        $this->assertSame(['en'], array_keys($result));
    }
}
