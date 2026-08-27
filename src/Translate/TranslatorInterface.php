<?php

declare(strict_types=1);

namespace Metaglot\Translate;

interface TranslatorInterface
{
    /** YouTube rejects titles longer than 100 characters. */
    public const TITLE_MAX = 100;

    /** YouTube rejects descriptions longer than 5000 characters. */
    public const DESC_MAX = 5000;

    /**
     * Produces search-aware localizations of a video title and description.
     *
     * Not a literal translation: for each target language the result is
     * phrased the way a native speaker would actually search for it.
     *
     * @param list<string> $targetLangs
     *
     * @return array<string, array{title: string, description: string}> keyed by language code
     */
    public function localize(string $title, string $description, string $sourceLang, array $targetLangs): array;
}
