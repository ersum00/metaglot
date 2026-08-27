# Prompting — why not literal translation, and how to tune it

The value of metaglot is not that it calls an LLM. It is *what it asks the LLM for*.

## Search queries are not translations

People do not search in translated sentences; they search in the idiom of their own
language and market. The Turkish title *"İstanbul'da gezilecek yerler"* translated
word-for-word is *"Places to visit in Istanbul"* — grammatically fine, and not what
anyone types. The query with actual volume is *"best things to do in Istanbul"*.
The same divergence exists everywhere: German viewers search *"… Test"* where English
speakers search *"… review"*; Spanish speakers ask *"cómo hacer …"* where a literal
rendering would say *"la manera de hacer …"*.

A video's localized title competes against titles written *natively* in the target
language. A translation that merely preserves meaning loses that competition. So the
prompt does not ask "translate this"; it asks, for each target language:

> produce a title and description that a native speaker would **search** for.

## What the default prompt enforces

The prompt lives in [`src/Translate/PromptBuilder.php`](../src/Translate/PromptBuilder.php)
and states these rules:

- **Search phrasing, not literal translation** — the core instruction.
- **Titles under 100 characters** — YouTube's hard limit. (The code clamps to 100
  characters afterwards regardless of what the model does, and descriptions to 5000 —
  the model is asked to comply, but never trusted to.)
- **Proper nouns, brand names, numbers and hashtags are preserved** — your channel
  name, product names and episode numbers must survive localization untouched.
- **The description's structure stays identical** — line breaks, links and timestamps
  are kept where they are; only the prose is localized. Timestamps that move break
  chapter markers.
- **Never invent facts** — the model localizes what is there; it does not embellish.
- **Answer as a single JSON object** — `{"<lang>": {"title": …, "description": …}}`,
  requested with `response_format: json_object` and parsed defensively (a markdown
  code fence around the JSON is tolerated and stripped).

Temperature is `0.3`: enough freedom to rephrase idiomatically, not enough to drift
from the source. Raise it and titles get creative; lower it and they collapse back
into literal translation.

## Adapting the prompt to your niche

Edit `systemPrompt()` in `PromptBuilder`. Additions that pay off:

- **A glossary of terms that must not be localized.** Gaming channels: game titles,
  ability names, patch numbers stay in English. Finance channels: ticker symbols,
  product names. Add a line like
  *"Keep the following terms exactly as written: …"*.
- **Terms that must be localized a specific way.** If your niche has an established
  translation ("machine learning" → "aprendizaje automático", not "aprendizaje de
  máquinas"), say so explicitly rather than hoping the model picks the right one.
- **Audience register.** *"Address the viewer informally (tú / du / sen)"* — or the
  opposite — keeps the localized voice consistent with your channel's voice.
- **Niche query patterns.** Cooking: English viewers search "recipe", Germans
  "Rezept einfach", Turks "nasıl yapılır". If you know the winning pattern in a
  market, put it in the prompt; the model then anchors titles to it.

After any prompt change, run a sample through `--dry-run` and read the titles it
*would* write before letting a real run touch your videos:

```sh
php bin/metaglot --channel=1 --dry-run
```

## Choosing a model

The default is `qwen2.5:14b-instruct` on a local Ollama — good multilingual coverage,
runs on a single consumer GPU, and metadata never leaves your machine. Practical
guidance:

- Smaller models (7B and below) produce noticeably more literal titles and are worth
  it only for closely related language pairs.
- If your target list includes languages the model is weak in (check its model card),
  a larger local model or a hosted endpoint via `LLM_ENDPOINT` / `LLM_MODEL` /
  `LLM_KEY` is the honest fix — partial results are handled gracefully (written
  languages are kept, missing ones retried on the next run), but chronically weak
  output is not something the pipeline can repair.
- Whatever the model, the 100/5000 character limits are enforced in code, and
  languages the model fails to return are simply retried later — a flaky model
  degrades throughput, not correctness.
