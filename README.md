# headline-options

A WordPress plugin that, on a click in the editor, asks a language model for several headline candidates, scores each one and the current headline with plain rules (length, clickbait words, numbers, names), and shows them side by side. It never replaces a headline on its own: the editor picks one, edits it, or ignores them all.

Written from scratch; not derived from any third-party plugin.

This project came out of the work on indianopinion.org and is written from scratch; it is independent of and not affiliated with any third-party plugin it integrates with.

Measured result: in a plain Hindi news sentence 37% of the Devanagari code points are combining marks (from the public script-share project), so a length limit in code points cuts Hindi headlines about a third short; this plugin counts grapheme clusters instead. No numbers from a deployment of this plugin are claimed yet.

## Contents

- [Install](#install)
- [Quick start](#quick-start)
- [Configuration](#configuration)
- [How it works](#how-it-works)
- [The rules](#the-rules)
- [Hooks and functions](#hooks-and-functions)
- [WP-CLI](#wp-cli)
- [Limitations](#limitations)
- [FAQ](#faq)
- [Licence](#licence)

## Install

Requirements: WordPress 6.4 or later, PHP 8.1 or later with `ext-mbstring`. `ext-intl` is optional (grapheme length and NFC). Runtime dependency: [`indianopinion/llm-json-php`](https://github.com/IndianOpinion/llm-json-php) for the model call, unless you plug in your own caller (see FAQ). Optional: [`indianopinion/script-share`](https://github.com/IndianOpinion/script-share) for the script check, [spend-meter](https://github.com/IndianOpinion/spend-meter) and [prompt-library](https://github.com/IndianOpinion/prompt-library) when active.

**With Composer.** The sibling packages are not on Packagist yet, so add them as VCS repositories:

```sh
composer config repositories.llm-json-php vcs https://github.com/IndianOpinion/llm-json-php
composer config repositories.headline-options vcs https://github.com/IndianOpinion/headline-options
composer require indianopinion/headline-options
```

The package type is `wordpress-plugin`, so `composer/installers` puts it in your plugins directory. To turn on the script check, also add `indianopinion/script-share` the same way. It is never bundled.

**Without Composer at runtime.** Copy the plugin folder to `wp-content/plugins/headline-options/`. Then either run `composer install --no-dev` once in that folder (it fetches llm-json-php into `vendor/`), or copy llm-json-php to `lib/llm-json-php/` inside the plugin folder: its `bootstrap.php` is loaded when there is no `vendor/`. Activate the plugin. Without the library the plugin stays active, scoring works, and generation is disabled with a notice that names the missing package.

**As a must-use plugin.** Copy the folder to `wp-content/mu-plugins/headline-options/` and [`examples/mu-loader.php`](examples/mu-loader.php) to `wp-content/mu-plugins/headline-options-loader.php`:

```php
<?php
require_once __DIR__ . '/headline-options/headline-options.php';
```

## Quick start

1. Activate the plugin. Generation is **off** on install, so nothing is spent.
2. Open **Settings, Headline options**. Choose a provider, paste a key (or define `HEADLINE_OPTIONS_PROVIDER_KEY` in `wp-config.php`), and enter one to three model ids.
3. Check the caps (50 a day for the site, 15 per user, 4 a minute per user by default), tick **Generation enabled**, and save.
4. Open a post. The **Headline options** panel sits in the document sidebar. Click **Suggest headlines**. Each candidate shows its score, band and a "Why" list; **Use** puts it in the title field of the editor, unsaved. Save the post yourself when you are happy.
5. Type any headline in **Score your own headline** to score it for free.

Score without WordPress (runs as is): `php examples/score-without-wordpress.php`.

## Configuration

Settings live under **Settings, Headline options** (capability `manage_options`, filter `headline_options_manage_capability`).

| Setting | Default | Range | Notes |
|---|---|---|---|
| Generation enabled | off | on, off | `HEADLINE_OPTIONS_DISABLED` true locks it off |
| Provider | OpenRouter | OpenRouter, OpenAI, OpenAI-compatible URL | |
| Compatible base URL | empty | `https://` URL | Only for the compatible provider |
| Provider key | none | | Write-only, shown masked. `HEADLINE_OPTIONS_PROVIDER_KEY` wins |
| Model chain | empty | 1 to 3 model ids | Tried in order by llm-json-php |
| Candidates per click | 5 | 3 to 10 | |
| Site daily cap | 50 | 0 to 10,000 | Generations, in the site timezone. 0 turns generation off |
| Per-user daily cap | 15 | 0 to 1,000 | |
| Per-user rate limit per minute | 4 | 1 to 30 | `Retry-After` on refusal |
| Input limit | 6,000 | 1,000 to 30,000 | Characters (graphemes) of article text sent |
| Minimum words | 40 | 10 to 500 | Shorter articles are refused before any spend |
| Post types | `post` | types that support a title | Filter `headline_options_post_types` |
| Style note | empty | 500 characters | Your house style, added to the prompt |
| Confirm before Use | on | | Each user can turn it off in the panel |
| Length pass range | 30 to 70 | 10 to 200 | |
| Length warn range | 20 to 90 | wider than pass | |

An empty number falls back to its default; there is no "unlimited". For no practical cap, set a large number on purpose.

Constants:

| Constant | Effect |
|---|---|
| `HEADLINE_OPTIONS_DISABLED` | `true` forces generation off on every site; the screen shows the switch locked |
| `HEADLINE_OPTIONS_PROVIDER_KEY` | Provider key; preferred over the settings field |
| `HEADLINE_OPTIONS_KEEP_DATA` | `true` keeps all data on uninstall |

Privacy: when an editor clicks **Suggest headlines**, the article text (as on screen, trimmed to the input limit), the current headline, the excerpt and the style note go to the provider you configure. No reader data, user email, author name or user id is sent.

## How it works

```
click "Suggest headlines"
  -> REST POST /headline-options/v1/posts/{id}/generate  (nonce, edit_post)
  -> switch and constant -> provider configured -> UTF-8 and size checks
  -> article to plain text (blocks, shortcodes stripped not run, tags, entities)
  -> minimum words (422, not counted) -> time left in the request
  -> post lock (409) -> site cap, user cap, rate limit (429, counted from here)
  -> spend-meter, when active (can only deny)
  -> one model call through llm-json-php with a JSON schema
  -> clean, drop, de-duplicate, keep N -> score -> sort
  -> store the set in post meta (last 5) -> respond
```

- **Nothing runs on save, publish, import or cron.** The plugin hooks no post lifecycle action. Generation is interactive, so `gen-queue` is not used: a queued job would leave the editor waiting.
- **"Use" changes the editor state only.** In the block editor it is a title edit in the `core/editor` store; the post stays dirty until you save, and the editor's own undo reverts it. In the classic editor it fills the title input. The plugin never writes `post_title`, the slug, the excerpt or any revision.
- **Counted when attempted.** A model call counts against the caps when it is made, success or failure, because a failed call may still be billed. Refusals before the call (switch, too short, caps, meter) cost nothing.
- **Atomic caps.** Day counters and the rate limit live in one option row that is changed only while a lock row is held. The lock is taken with `INSERT IGNORE` on the unique option name, so two requests at cap minus one cannot both pass.
- **Generation fails closed, scoring fails open.** Every generation failure returns an error and leaves the headline alone. A scoring rule that breaks is reported as "not applicable" and the others still run.
- **Stored:** settings, the key (autoload off), today's counters, the last error code and time, and the last five candidate sets per post (protected meta, not revisioned, not in REST post responses). Sets keep a hash of the content and of the prompt, never the text. Uninstall removes it all.

The design and every decision are in [`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md). The requirements are in [`docs/PRD.md`](docs/PRD.md).

## The rules

Each rule says pass, warn, fail or not applicable, with a one-line reason. Pass earns its full weight, warn half, fail nothing; not applicable counts neither way. The total is `round(100 * earned / possible)`; bands are good from 75, fair from 50, weak below.

| Rule | Weight | What it checks |
|---|---|---|
| `length` | 25 | Graphemes (or code points less marks without ext-intl). Pass 30 to 70, warn 20 to 90 |
| `clickbait` | 20 | Stock phrases, one warns, two fail. English list built in; other languages are not applicable until you add a list |
| `withheld_subject` | 5 | "This is why...", "They did it..." or a trailing question mark warns |
| `numeral_presence` | 5 | A specific number earns a small bonus; none is not applicable |
| `numeral_support` | 20 | Every number in the headline appears in the article, compared as digit strings after native digits are mapped and grouping (`1,200`, `1,00,000`) removed |
| `entity_support` | 20 | Every name (capitalised run, acronym, tag or category name, known entity) appears in the article |
| `shouting` | 5 | Two or more caps words that are not acronyms in the article warn; mostly caps fails |
| `punctuation` | 5 | `!!`, `?!`, `...`, an ellipsis or a trailing `!` warns |
| `emoji` | 5 | Emoji, pictographs and styled letters fail; currency and the degree sign pass |
| `script_match` | 10 | Headline and article in the same writing system (needs script-share) |

Closeness to the current headline (shared word ratio) is shown, not scored. Scores are advice: they never hide, block or reorder the current headline, and never disable **Use**. They measure craft and accuracy signals, not clicks or traffic.

## Hooks and functions

Functions: `headline_options_score($headline, $content, $context)`, `headline_options_score_many()`, `headline_options_generate($post_id, $state, $args)` (same checks and caps as the REST route, throws typed exceptions, never writes the title), `headline_options_is_enabled()`, `headline_options_usage()`.

Actions: `headline_options_generated` (set), `headline_options_failed` (post id, code, message), `headline_options_chosen` (post id, set id, index, user id), `headline_options_cap_reached` (scope, count, cap), `headline_options_log` (level, message, context).

Filters: `headline_options_post_types`, `headline_options_capability`, `headline_options_manage_capability`, `headline_options_post_language`, `headline_options_input_text`, `headline_options_prompt`, `headline_options_caller`, `headline_options_chain`, `headline_options_llm_client`, `headline_options_rules`, `headline_options_weights`, `headline_options_length_bounds`, `headline_options_clickbait_terms`, `headline_options_withheld_patterns`, `headline_options_number_words`, `headline_options_known_entities`, `headline_options_bands`, `headline_options_daily_cap` and `headline_options_user_cap` (can only lower), `headline_options_sets_kept`.

REST (namespace `headline-options/v1`): `POST /posts/{id}/generate`, `POST /posts/{id}/score`, `GET /posts/{id}/sets`, `POST /posts/{id}/choice`, `GET /status`. Error codes: `headline_options_disabled`, `_not_configured`, `_too_short`, `_busy`, `_daily_cap`, `_user_cap`, `_rate_limited`, `_meter_refused`, `_provider_failed`, `_provider_credit`, `_refused`, `_no_candidates`, `_storage`, `_bad_input`, `_no_time` (all prefixed `headline_options`).

A custom rule implements `IndianOpinion\HeadlineOptions\Scoring\Rule` (`id()`, `weight()`, `evaluate(Headline, Article): RuleResult`) and is added through `headline_options_rules`. More in [`examples/`](examples).

## WP-CLI

| Command | Effect |
|---|---|
| `wp headline-options status [--format=json]` | Switch, constant, key present, caps, today's counts, last error, length unit, script check |
| `wp headline-options score <headline> [--post=<id>] [--content-file=<file>] [--format=table\|json]` | Scores with no model call |
| `wp headline-options generate <post-id> [--count=<n>] [--format=table\|json]` | Generates from the saved post, counted against the site cap; never writes the title |
| `wp headline-options sets <post-id>` | Stored sets |
| `wp headline-options reset-usage [--yes]` | Zeroes today's counters |

Exit codes: 0 success, 1 error, 3 disabled, 4 capped.

## Limitations

- Entities are found by plain rules, not by a model. In scripts without case (Devanagari, Bengali, Tamil, Arabic and the like) only tags, categories, Latin words, the names you supply through `headline_options_known_entities`, and the names the model lists with its candidates are checked. A headline you type yourself has no model list, and a name the model uses without listing it is not checked; when no name is found the rule is not applicable.
- The clickbait, withheld-subject and number-word lists are English only in 0.1.0. Add yours through the filters (see [`examples/language-lists.php`](examples/language-lists.php)).
- `3,500` is read as three thousand five hundred. A decimal comma is kept only when the digits after it are not a group of three.
- Without ext-intl, length is code points less combining marks and format characters, which is close to but not exactly what a reader sees for some conjuncts.
- The script check needs script-share. Without it the rule is not applicable and the settings screen says so.
- Caps count generations, not money. Money caps are spend-meter's job when it is active.
- SEO titles, social titles and slugs are out of scope.

## FAQ

**Does it ever change a headline by itself?** No. Generation needs a click, and even **Use** only edits the unsaved editor state.

**Can I run it without llm-json-php?** Yes. Return an object implementing `IndianOpinion\HeadlineOptions\Generation\ModelCaller` from the `headline_options_caller` filter. The kill switch, caps, lock and spend-meter checks still apply. See [`examples/own-caller.php`](examples/own-caller.php).

**Why is my click refused with "Write a little more of the article first."?** The article has fewer than the minimum words after blocks, shortcodes and tags are removed. A post made only of a gallery or an embed has none.

**Why does a failed call count against the cap?** The provider may have billed it.

**Why is my Hindi headline's entity rule "not applicable"?** Devanagari has no capital letters, so names cannot be found by case. Add tags or known entities.

**Where do I report a security problem?** See [SECURITY.md](SECURITY.md).

## Licence

MIT. Copyright (c) 2026 Indian Opinion. See [LICENSE](LICENSE).
