# headline-options: architecture

This document describes how the plugin is built and records every decision,
including the rulings on the open questions of `docs/PRD.md` section 13.

## Modules

| Path | Role | Needs WordPress |
|---|---|---|
| `src/Text/` | `Utf8`, `Length` (grapheme or code point less marks), `PlainText` (content to text), `Trimmer`, `Cleaner` (case 17), `Numbers` (digit strings), `Regex` | No |
| `src/Scoring/` | `Rule` interface, `Headline`, `Article`, `RuleResult`, `ScoreResult`, `Scorer`, `ScorerFactory` (filters), `Lists` (defaults) | No (filters are no-ops without it) |
| `src/Scoring/Rules/` | The ten built-in rules | No |
| `src/Generation/` | `Generator` (the ordered pipeline), `PromptBuilder`, `OutputSchema`, `ModelCaller` interface, `LlmJsonCaller`, `CandidateProcessor`, `CandidateSet`, `ErrorLog` | Generator: only through injected services |
| `src/Storage/` | `OptionTable` (atomic insert), `WpdbOptionTable`, `MemoryOptionTable`, `Mutex`, `UsageStore`, `PostLock`, `SetStore` | `WpdbOptionTable` and `SetStore` only |
| `src/Integration/SpendMeter.php` | spend-meter contract 1 through its hooks | Yes |
| `src/WordPress/` | `Plugin` (wiring), `Services` (object graph, fresh settings), `RestController`, `Admin`, `Editor`, `Cli`, `PostContext`, `Uninstaller` | Yes |
| `assets/core.js` | Editor logic shared by both editors, rendering through a `createElement`-style function | No (tested in Node) |
| `assets/panel.js`, `assets/classic.js` | Block editor panel and classic meta box | Browser |

## Data flow

1. The editor's browser posts the on-screen title, content and excerpt to
   `POST /headline-options/v1/posts/{id}/generate` with the REST nonce.
2. The permission callback checks sign-in (401), body size (413, before any
   parsing), the post (404 for missing or trashed; autosaves and revisions
   map to the parent), the use capability, `edit_post` and the post type
   (403).
3. `Generator::run()` applies, in this order: constant, setting, a cap of 0
   (503 disabled); caller available (503 not configured); UTF-8 and count
   (400); plain text and the minimum word count (422); time left (503); the
   per-post lock (409); site cap, user cap and rate limit (429); spend-meter
   (429); the model call; cleaning, scoring and sorting; saving the set.
4. The response carries the set (sorted, current headline scored),
   `notes`, `saved` and `usage`. Nothing touches the post.
5. "Use" in the browser edits the title in the editor store and records the
   choice through `POST /posts/{id}/choice`, which writes only to the set.

## Rulings on the PRD open questions

1. **Length without ext-intl.** Ruling: count code points minus combining
   marks (and format characters); no grapheme table. Reason: a table is a
   second copy of Unicode data to keep current, and the fallback matches
   graphemes for Latin text and is close for Indic text. `Length` reports the
   unit in use; the settings screen, `/status` and the CLI show it.
2. **script-share.** Ruling: optional, never bundled. When
   `IndianOpinion\ScriptShare\Analyser` exists the script rule uses it;
   otherwise the rule is `na` with "Install script-share for the script
   check." It is a dev dependency only, so the tests exercise the real
   library. Headline and body each need a majority script (`dominant(0.5)`),
   so an English report quoting a Hindi line stays English (case 77).
3. **llm-json-php.** Ruling: a permitted Composer `vcs` dependency, required
   at runtime, never bundled in the repository. A caller filter,
   `headline_options_caller`, takes any `ModelCaller`, so a site can run
   with no llm-json-php at all. When neither is present the plugin stays
   active, scoring works and generation reports `library_missing`.
4. **What caps count.** Ruling: generations. Money caps belong to
   spend-meter, which is asked through `spend_meter_pre_spend` when its
   contract 1 is present, after this plugin's own caps.
5. **Where sets live.** Ruling: post meta `_headline_options_sets`, last 5
   (filter `headline_options_sets_kept`). Protected, not revisioned, not in
   REST post responses, deleted with the post.
6. **Language lists.** Ruling: English-only clickbait, withheld-subject and
   number-word lists in 0.1.0, all filterable by primary language subtag. A
   language with no list makes the rule `na`.
7. **Entities in caseless scripts.** Ruling: rely on the model's returned
   entity list, and document the limit. The output schema therefore carries
   one key beyond PRD 5.6: `entities`, a list (at most 30) of the names the
   candidates use, written as in the article. Those names, cleaned, join
   tags, categories, Latin tokens and `headline_options_known_entities` as
   entity candidates, and each one that appears in a headline must appear
   in the article. Reason: in Devanagari, Bengali, Tamil or Arabic script
   there is no case to find a name by, and the model knows which names it
   used; the plugin still verifies them against the text, so a name the
   model invents and lists fails. Limit: a headline typed by the editor has
   no model list, and a name the model uses but does not list is not
   checked. No "rare word" heuristic is used.
8. **Classic editor.** Ruling: a reduced meta box with Suggest, the scored
   list and a Use button (plus Copy). No own-headline box and no Previous
   list beyond the session's re-render.
9. **SEO title fields.** Ruling: out of scope.

## Other decisions

- **Atomic counters (case 53).** WordPress `add_option` is not atomic: it
  writes with `ON DUPLICATE KEY UPDATE`, so two racing callers can both get
  true. The plugin takes a lock row with `INSERT IGNORE` on the unique
  `option_name` (the technique core uses for its upgrade lock), then reads
  and writes the counter row directly through `$wpdb` while holding it. The
  lock value is its expiry, so a crashed request frees it after 10 seconds;
  an expired lock is removed only if its value is unchanged. A request
  that cannot take the lock within 2 seconds, or cannot write, gets 503
  `headline_options_storage`: the cap is never assumed.
- **Rate limit storage.** The PRD lists transients for the rate limit. The
  timestamps live in the same counter row instead, under the same lock, so
  the per-minute limit is as atomic as the day caps. No rate transients
  exist.
- **Generation lock storage.** The PRD lists a transient per post. Transients
  cannot refuse a second writer, so the lock is an option row
  `headline_options_lock_{id}` taken with `INSERT IGNORE`, holding its
  expiry (request deadline plus 30 seconds). Uninstall deletes the prefix.
- **Releasing a count.** A count is given back when the call is never
  attempted: spend-meter denied, or the caller failed before sending (a
  `ProviderException` with `attempted() === false`). This keeps case 16
  ("counted when attempted") exact.
- **Cap of 0.** Either cap at 0 means generation is off: 503
  `headline_options_disabled` with reason `cap_zero`, and `/status` shows
  `enabled: false`. CLI runs have no user, so only the site cap applies to
  them.
- **Cap filters lower only.** `headline_options_daily_cap` and
  `headline_options_user_cap` are clamped to the setting.
- **Warn points.** Pass earns the full weight, warn half (rounded down),
  fail and `na` nothing. `na` also adds nothing to the possible points.
- **Withheld-subject weight.** The PRD's weight table has no entry for case
  38. It gets 5 points, the same as the other style rules, and is
  filterable through `headline_options_weights`. A trailing `?` counts here,
  never under punctuation.
- **No entity found.** Case 42 makes the entity rule `na` when no entity is
  found in caseless scripts; the same applies to Latin headlines with no
  name, so the rule never passes by default (lesson 14.5). In a mostly-caps
  headline capitalisation says nothing, so only tags and known entities
  count there; the shouting rule handles the caps.
- **Capitalised runs.** A run like "Reserve Bank Governor" is supported when
  the whole phrase is in the article, or when every word in it is. This
  keeps Title Case headlines from failing on every word.
- **Number words.** A number word in the headline matches the same word or
  its digit value in the article, and a digit number matches its word. Scale
  words (`lakh`, `crore`, `million`) carry their values.
- **Closeness.** Jaccard ratio of case-folded word sets, two decimals. Not
  computed for the current headline itself or when it is empty.
- **Content hash.** sha256 of the raw content string the browser sent (or
  `post_content` for the CLI). The browser hashes the same string with
  `crypto.subtle` and passes it to `GET /sets?content_hash=` to get `stale`
  flags, so "generated for an earlier version of the text" needs no extra
  server work.
- **Prompt-library slug.** The PRD names `headline-options/generate`.
  Template slugs are plain slugs, so the template is
  `headline-options-generate`, consumer `headline-options`. Rendering
  passes the built-in definition as the fallback; any `WP_Error`, throw, or
  render without `system` and `user` strings falls back to the built-in
  prompt and logs a warning at most once an hour (a transient). The output
  schema is always the plugin's own.
- **Prompt text.** The built-in prompt is generic: N candidates, language
  and writing system, single line under the upper length bound, an angle
  label, only facts in the text, no clickbait, unanswered questions or
  emoji, JSON only. Article, excerpt, current headline and style note go in
  delimited blocks, and the system part says block content is material,
  not instructions. The style block is left out when the note is empty.
- **Language in the prompt.** The locale string (`en_GB`, `hi_IN`) is passed
  as is; models read locale codes reliably and no name table is needed.
- **Time budget (case 72).** Remaining time is `max_execution_time` less the
  time since `REQUEST_TIME_FLOAT`. The library deadline is that less 5
  seconds, capped at 60; under 8 seconds the request is refused before the
  call. With no PHP limit (CLI) the deadline is 60 seconds.
- **Meter answer of null.** With `SPEND_METER_API` equal to 1 defined but
  the filter returning null, the plugin denies (`no_decision`): the meter
  is present and did not allow the spend.
- **Transport and store.** The default client uses llm-json-php's
  `WordPressTransport` and `WordPressTransientStore` when available. The
  filter `headline_options_llm_client` can replace the client (tests use it
  to inject the library's mock transport).
- **Confirm preference.** Stored as the user meta
  `headline_options_skip_confirm` (registered, `show_in_rest`, writable only
  by that user), because a boolean false is stored as an empty string in
  WordPress meta and would read as "not set".
- **Classic editor detection.** The meta box is added only when
  `use_block_editor_for_post()` is false, so block editor users do not get
  both.
- **Errors.** Every refusal is a typed exception with a stable `code()` and
  HTTP status; the REST layer turns it into a `WP_REST_Response` with the
  standard `code`, `message`, `data.status` shape (so it can carry
  `Retry-After`). No `@` operator; invalid filter regexes are detected with
  a temporary error handler.
- **Logs.** `Logger` drops any context value that is not a short scalar, so
  article text, headline text and keys cannot reach a log line.

## Testing

- PHPUnit 10 with a stub WordPress layer in `tests/stubs/` and llm-json-php's
  `MockTransport`. Tests that define constants run in separate processes.
- `tests/js/core.test.js` runs under `node --test` against `assets/core.js`
  with a fake `createElement`.
- PHPStan level 8 over `src` with `php-stubs/wordpress-stubs`. phpcs PSR-12.
- Every acceptance test T1 to T76 of PRD section 12 is named in a test
  method or test docblock. T7 also asserts the plugin registers no post
  lifecycle hook at all.
