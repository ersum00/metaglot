<?php

declare(strict_types=1);

namespace Metaglot\Tests\Translate;

use Metaglot\Config;
use Metaglot\Http;
use Metaglot\Translate\OpenAiCompatible;
use Metaglot\Translate\PromptBuilder;
use PHPUnit\Framework\TestCase;

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
}
