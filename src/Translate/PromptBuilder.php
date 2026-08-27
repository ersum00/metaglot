<?php

declare(strict_types=1);

namespace Metaglot\Translate;

/**
 * Builds the LLM messages for search-aware localization.
 *
 * NOT a literal translation: the prompt asks for titles a native speaker of
 * the target language would actually SEARCH for. This is the core value of
 * the product.
 */
final class PromptBuilder
{
    public function systemPrompt(): string
    {
        return <<<TXT
        You are a YouTube SEO localizer. For each target language produce a title and
        description that a native speaker would SEARCH for, not a literal translation.
        Rules: keep the title under 100 characters; preserve proper nouns, brand names,
        numbers and hashtags; keep the description structure (line breaks, links, timestamps)
        identical to the source; never invent facts.
        Return ONLY a JSON object: {"<lang>": {"title": "...", "description": "..."}}
        TXT;
    }

    /**
     * @param list<string> $targetLangs
     */
    public function userPayload(string $title, string $description, string $sourceLang, array $targetLangs): string
    {
        return (string) json_encode([
            'source_language'  => $sourceLang,
            'target_languages' => $targetLangs,
            'title'            => $title,
            'description'      => mb_substr($description, 0, TranslatorInterface::DESC_MAX),
        ], JSON_UNESCAPED_UNICODE);
    }
}
