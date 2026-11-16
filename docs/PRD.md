# headline-options: product requirements

Status: draft for review. Version target: 0.1.0.
Plugin slug: `headline-options`. Namespace: `IndianOpinion\HeadlineOptions`.
Global prefix for functions, hooks, options and meta: `headline_options_`.

Written from scratch; not derived from any third-party plugin. This PRD is
written from the one-line description of the project, public WordPress
documentation and the public PRDs of sibling projects. It describes no
private code.

## 1. Problem

An editor writes one headline, often in a hurry, and publishes it. Nobody
sees the three better ones that were never written. When an editor does ask
a language model for headlines, the usual tools make two mistakes. They
overwrite the title field without asking, so a draft headline is lost and
an unchecked line goes live. Or they return ten lines with no way to tell a
sound headline from a clickbait one, and the editor has to judge each line
cold.

A model also invents. A headline that says "12 dead" over a report that
says "at least ten" is a correction waiting to happen. So is a headline
that names a minister the article never mentions. Plain rules catch most of
this without a second model call: count the length, look for stock
clickbait phrases, check that every number and every name in the headline
appears in the article.

Length is easy to count wrongly. The public script-share PRD measured that
37% of the Devanagari code points in a plain Hindi news sentence are
combining marks. A length limit written in code points, or worse in bytes,
therefore cuts Indic headlines far shorter than the reader sees them.

headline-options asks a model for several candidates on a click, scores
every candidate and the current headline with transparent rules, and shows
them side by side in the editor. The editor picks one, edits it, or
ignores them all. The plugin never changes a headline on its own.

No measured result from a deployment of this plugin is claimed. It is
written from an idea; the first release records its own numbers.

## 2. Goals and non-goals

Goals:

1. On an explicit click in the post editor, ask a language model for N
   headline candidates for the post (default 5, allowed 3 to 10).
2. Score each candidate, and the current headline, with plain, documented,
   deterministic rules: length, clickbait words and patterns, numerals,
   named entities, punctuation and case, script match, and closeness to the
   current headline.
3. Show the candidates in the editor with their score and a per-rule
   breakdown, so the editor sees why a line scored as it did.
4. Let the editor copy a candidate or place it in the title field with a
   second click. Placing it changes only the unsaved editor state. Saving
   remains the editor's own action.
5. Let the editor type any headline and score it with the same rules, with
   no model call and no cost.
6. Keep spend bounded: a daily cap of generations per site, a per-user cap,
   a per-minute rate limit, and a kill switch, all in the plugin's own
   settings, honoured even when a site-wide spend meter is present.
7. Work with no other plugin present. Integrate softly, through their
   published hooks, with `spend-meter` (spend checks) and `prompt-library`
   (prompt override) when they are active.
8. Make every rule, list and weight replaceable through filters.

Non-goals:

- Automatic replacement of any headline, ever. No generation on save, on
  publish, on import, on schedule or in bulk.
- A/B testing of headlines on the live site, or rotating titles for
  readers.
- SEO titles, social titles, meta descriptions, slugs or excerpts. The
  plugin works on the post title only. A sibling project covers meta.
- Rewriting the article body.
- Language identification. The plugin compares writing systems, not
  languages (see 4.6.8).
- Named entity recognition by a model or a library. Entities are found by
  plain rules (4.6.6). The rule is a cheap check, not linguistics.
- Predicting clicks or traffic. The score measures craft and accuracy
  signals only. The README says so.
- Hosting any model. The operator brings a provider and a key.

## 3. Users and setting

- **Editors and authors** writing in the WordPress block editor, or in the
  classic editor, who want a few alternative headlines and a quick check of
  their own.
- **Site administrators** who install the plugin, add a provider key, set
  the caps and decide who may use it.
- **Developers** who replace a rule, add a clickbait list for a language,
  or route the prompt through their own prompt store.

It runs inside WordPress 6.4 or later on PHP 8.1 or later. The model call
happens in a REST request made by the editor's browser, so it runs on the
web tier, under the web server's time limit. Nothing runs from WP-Cron.
WP-CLI commands exist for operators and tests.

## 4. Behaviour

Numbered cases. Each has at least one acceptance test in section 12.

### 4.1 Access and switches

1. The panel appears only for post types that support `title` and are in
   the enabled list (default: `post`). Other types show nothing.
2. A user sees the panel when they can `edit_post` for that post and hold
   the plugin's use capability (default `edit_posts`, filterable).
3. A user who cannot `edit_post` the post gets HTTP 403 from every REST
   route for that post, even with a valid nonce.
4. Every REST route requires the standard REST nonce (cookie auth) or an
   application password. A request with neither gets 401.
5. Kill switch on (setting, or constant `HEADLINE_OPTIONS_DISABLED` true):
   the generate route returns 503 with code `headline_options_disabled`
   and makes no model call. Scoring still works, because it costs nothing.
   The panel shows "Suggestions are switched off by the site administrator."
6. The constant wins over the setting. With the constant true, the setting
   cannot switch generation on, and the settings screen shows the switch as
   locked with the reason.
7. No provider key configured: the generate button is disabled with
   "No model provider is set up." and the route returns 503 with code
   `headline_options_not_configured`. Scoring works.

### 4.2 Generating candidates

8. Generation happens only when the user clicks "Suggest headlines". No
   code path calls the model on load, on save, on autosave, on publish, on
   a schedule, from an import, or from a hook fired by another plugin.
9. The browser sends the post id and the current editor state: title,
   content and excerpt as they are in the editor now, unsaved changes
   included. The server uses these, not the saved post, so the editor gets
   candidates for what is on screen.
10. The server re-checks capability for that post id, then builds the model
    input from the supplied state:
    a. content rendered to plain text: blocks parsed, shortcodes stripped
       (not executed), tags removed, entities decoded, whitespace collapsed;
    b. trimmed to the input limit (default 6,000 characters, measured in
       grapheme clusters) at the last sentence end before the limit, or the
       last word boundary if there is no sentence end;
    c. the current title, decoded from HTML entities;
    d. the excerpt when non-empty.
11. Content with fewer than the minimum words (default 40) after step 10a
    is refused before any spend: HTTP 422, code `headline_options_too_short`,
    message "Write a little more of the article first." It does not count
    against any cap.
12. The post language for the prompt is, in order: the value of the filter
    `headline_options_post_language`; the post's language from a
    multilingual plugin if that plugin exposes it through that same filter;
    the site locale. The prompt asks for headlines in that language and in
    the same writing system as the article.
13. The prompt (section 5.6) asks for exactly N candidates as JSON. It
    tells the model to use only facts, numbers and names in the supplied
    text, and to vary the angle. It carries no house style. Operators add
    their own guidance through the style note setting or a prompt override.
14. The call goes through `llm-json-php` with a JSON schema. Schema
    validation, retries and provider fallback are that library's job.
15. One generation per post at a time. A second click on the same post
    while one is in flight gets 409, code `headline_options_busy`. The lock
    expires after the request time limit plus a margin, so a crashed request
    never blocks the post for long.
16. The generation is counted against the daily and per-user caps when the
    model call is attempted, not when it succeeds. A failed call still
    spent money or may have.

### 4.3 Cleaning candidates

17. Each candidate string is cleaned before scoring:
    a. Unicode NFC normalisation when `ext-intl` is present;
    b. leading list markers removed (`1.`, `1)`, `-`, `*`, `•`, `#`);
    c. one pair of wrapping quotes removed, straight or curly, in any of
       the common forms;
    d. Markdown emphasis markers removed (`**`, `__`, backticks);
    e. HTML tags removed, entities decoded;
    f. a prefix such as `Headline:` or `Title:` removed (case-insensitive,
       English only, list filterable);
    g. whitespace collapsed, control and format characters removed except
       ZWJ and ZWNJ inside a word, which Indic scripts need;
    h. one trailing full stop removed; a trailing `?` or `!` kept.
18. Empty after cleaning: dropped.
19. Duplicates: two candidates that match after case folding, removal of
    punctuation and collapsing of whitespace are one candidate; the first
    is kept.
20. A candidate equal to the current headline under the same comparison is
    dropped, and the response notes that the model repeated the current
    headline.
21. A candidate longer than the hard cap (default 200 grapheme clusters) is
    dropped, not truncated. Truncation would make a new headline nobody
    wrote.
22. When the model returns more than N, the first N after cleaning are
    kept. When it returns fewer than N, the panel shows what came back and
    says "The model returned 3 of 5." No second call is made automatically.
23. When no candidates survive cleaning, the response is a 502 with code
    `headline_options_no_candidates`, and the panel says so. The call still
    counts against the caps (case 16).
24. Each candidate may carry a short `angle` label from the model (for
    example "the number", "the person", "the consequence"). It is cleaned
    like a candidate, capped at 60 characters, and shown in small type. It
    is never scored.

### 4.4 Showing and choosing

25. The panel lists the current headline first, marked "Current", with its
    score, then the candidates sorted by score, highest first. Ties keep
    the model's order.
26. Each row shows the headline, the total score (0 to 100), a band
    (`good`, `fair`, `weak`), the length in characters, and an expandable
    list of every rule result: pass, warn, fail or not applicable, with a
    one-line reason.
27. Each candidate has two buttons: "Copy" (to the clipboard) and "Use".
    "Use" sets the title in the editor state only. In the block editor this
    is an edit to the post title that leaves the post dirty and unsaved; in
    the classic editor it fills the title input. Nothing is saved by the
    plugin. The editor can undo it with the editor's own undo.
28. "Use" asks for confirmation when the current headline is not empty and
    differs from the candidate: "Replace the current headline in the
    editor? It is not saved until you save the post." The confirmation can
    be turned off per user in the panel.
29. After "Use", the panel keeps the old headline in its list, marked
    "Previous", for the rest of the session, so it can be put back.
30. The plugin records the choice (which candidate, from which set, by
    which user, when) through a separate REST call, only after the user
    clicked "Use". The record changes no post field. A failed record never
    blocks or reverts the choice.
31. The last generated set is shown again when the editor reopens the post,
    marked with its age ("generated 3 hours ago for an earlier version of
    the text" when the content hash differs from the current one).
32. No candidate text is ever written to `post_title`, `post_name`, the
    excerpt, any SEO field or any revision by this plugin.

### 4.5 Scoring your own headline

33. A text box in the panel scores any headline the user types, with the
    same rules, against the current editor content. No model call, no cap,
    no lock.
34. The score updates after the user stops typing (debounced, default
    400 ms), through the score route. The route accepts up to 20 headlines
    in one request.
35. Scoring is deterministic: the same headline, content, settings and rule
    lists give the same result on every run and every PHP version.

### 4.6 Rules

Every rule returns: a status (`pass`, `warn`, `fail`, `na`), points earned,
points possible, a stable rule id, and a reason string. `na` contributes
nothing to either earned or possible points. The total is
`round(100 * earned / possible)`, or null when every rule is `na`. Bands:
`good` at 75 or more, `fair` at 50 to 74, `weak` below 50 (filterable).
Default weights are in section 7.

36. **Length.** The unit is the grapheme cluster when `ext-intl` is
    present (`grapheme_strlen`), otherwise the code point excluding
    combining marks (General_Category M) and format characters. The two
    units give the same answer for Latin text without combining marks.
    Defaults: pass at 30 to 70, warn at 20 to 29 or 71 to 90, fail below 20
    or above 90. Word count is reported beside it, not scored. The settings
    screen says which unit is in use.
37. **Clickbait phrases.** A built-in English list of stock phrases and
    patterns (examples: "you won't believe", "will blow your mind", "what
    happened next", "this one trick", "here's why", "everything you need to
    know", "the internet is", "goes viral", "shocking"). Matching is
    case-insensitive, on word boundaries, after curly quotes and
    apostrophes are folded to straight ones. One match is a warn, two or
    more a fail. The reason names the matched phrase. Lists for other
    languages are added through the filter `headline_options_clickbait_terms`
    keyed by language; a language with no list makes the rule `na`, never
    a pass.
38. **Withheld subject.** A headline that leads with a bare demonstrative
    or pronoun and withholds its subject ("This is why...", "They did
    it...", "Here is what...") is a warn. English only by default;
    filterable.
39. **Numerals: presence.** A headline with a specific numeral (digits in
    any script, or a written number word from a filterable list) earns the
    small presence points. A headline with none is `na` for this sub-rule,
    not a fail. Many good headlines carry no number.
40. **Numerals: support.** Every number in the headline must appear in the
    article text. Numbers are compared as values: `1,200`, `1200` and
    `1.2 thousand` are not all recognised, so the rule compares digit
    strings after removing grouping separators (comma, thin space, the
    Indian grouping `1,00,000`), and maps native digits (Devanagari,
    Bengali, Tamil, Arabic-Indic and others in Unicode `Nd`) to ASCII
    first. A number in the headline with no match in the text is a fail,
    naming the number. Years (four digits, 1800 to 2100) follow the same
    rule. Percent signs and currency symbols are ignored for the match.
41. **Named entities: support.** Candidate entities in the headline are
    found by plain rules: runs of one or more capitalised words not at the
    start of the headline, all-caps tokens of 2 to 6 letters (acronyms),
    and any word that appears in the post's tags or categories. Each must
    appear in the article text (case-insensitive, with a simple
    possessive and plural strip: `'s`, `s'`, trailing `s`). An unsupported
    entity is a fail, naming it.
42. **Named entities: scripts without case.** For headlines whose letters
    are mostly in a script with no upper and lower case (Devanagari,
    Bengali, Tamil, Arabic and the like), the capitalisation part of case
    41 does not apply. The rule then checks only tags, categories, Latin
    tokens inside the headline, and any entity list supplied through the
    filter `headline_options_known_entities`, plus the names the model
    lists in the `entities` key of its output (5.6). When none of these
    yield an entity, the rule is `na`, never a pass.
43. **Shouting.** Two or more all-caps words of four or more letters that
    are not known acronyms (from the article text, where they also appear
    in caps) is a warn. More than half the letters in caps is a fail.
44. **Punctuation.** `!!`, `?!`, `!?`, three or more dots, an ellipsis
    character, or a trailing `!` are a warn. A trailing question mark alone
    is a warn under the withheld-subject family, not under this rule, so
    one headline is not penalised twice for one feature.
45. **Emoji and decoration.** Any emoji, pictograph or decorative symbol
    (General_Category So, except currency and the degree sign) is a fail.
    Styled "mathematical bold" letters are a fail.
46. **Script match.** The headline's dominant writing system must match the
    article's. When the soft dependency `script-share` is installed (the
    class `IndianOpinion\ScriptShare\Analyser` exists), the rule uses it:
    dominant script of the headline and of the body are computed
    separately; mismatch is a fail. Without it, the rule is `na` and the
    breakdown says "Install script-share for the script check." No home
    made script table is shipped.
47. **Closeness to the current headline.** Reported, not scored: a
    similarity value (shared word ratio) between the candidate and the
    current headline, so the editor can see which lines are real
    alternatives and which are rewordings.
48. **Custom rules.** The filter `headline_options_rules` receives the rule
    list and may add, remove or replace rules. A rule is an object
    implementing the `Rule` interface (5.3). A rule that throws is reported
    as `na` with reason "rule error", logged, and does not stop the others.
49. Scores are advice. The panel never hides, blocks or reorders the
    current headline because of its score, and never disables "Use" on a
    low-scoring candidate.

### 4.7 Caps, rate limits and spend

50. A daily cap of generations per site (default 50) in the site timezone.
    When reached, the generate route returns 429, code
    `headline_options_daily_cap`, with the local time the cap resets. No
    model call is made.
51. A per-user daily cap (default 15). Same behaviour, code
    `headline_options_user_cap`.
52. A per-user rate limit (default 4 per minute). Code
    `headline_options_rate_limited`, with a `Retry-After` header.
53. Counters are incremented atomically. Two simultaneous requests at cap
    minus one cannot both pass. The builder chooses the mechanism (a single
    conditional `UPDATE`, or `add_option` as a lock) and records it.
54. A cap of 0 means generation is off. An empty cap setting is invalid and
    falls back to the default; there is no "unlimited" value through the
    screen. An operator who wants no cap sets a very large number on
    purpose.
55. When `spend-meter` is active (constant `SPEND_METER_API` equals 1), the
    plugin asks it before the call through `spend_meter_pre_spend` with
    purpose `headline-options.generate`, caller `headline-options`, the
    provider and model of the first route, and an input token estimate. If
    the decision is not `allowed === true`, no call is made and the route
    returns 429, code `headline_options_meter_refused`, with the meter's
    reason code. If a reservation id is returned, exactly one of
    `spend_meter_record` or `spend_meter_release` follows.
56. The plugin's own caps and kill switch apply first, before spend-meter
    is consulted. spend-meter can only deny further. A missing or newer
    contract version is treated as no meter (with a one-line notice on the
    settings screen for a newer version).
57. gen-queue is not used. Generation is interactive; a queued job would
    leave the editor waiting with no answer. The README says so.

### 4.8 Failure modes

| # | Failure | What happens | Open or closed |
|---|---|---|---|
| 58 | Provider timeout or chain exhausted | 502, code `headline_options_provider_failed`, message with the library's reason; counted against caps; last error stored | Closed: no candidates, current headline untouched |
| 59 | Provider credit exhausted | 502, code `headline_options_provider_credit`; a notice on the settings screen until the next success | Closed |
| 60 | Model output fails the schema after the library's retries | As 58, reason `invalid_output` | Closed |
| 61 | Model refuses (content filter or refusal) | 502, code `headline_options_refused`; panel says the provider declined this article | Closed |
| 62 | `llm-json-php` missing (bad install) | Plugin stays active, generate disabled with an admin notice naming the missing package; scoring works | Closed for generation, open for scoring |
| 63 | spend-meter returns an unknown reason code | Treated as a deny (its contract says so) | Closed |
| 64 | spend-meter filter throws | Caught, logged, treated as a deny | Closed |
| 65 | prompt-library returns `WP_Error` or a render without the needed shape | Built-in prompt used, warning logged once per hour | Open: the default prompt is safe |
| 66 | Counter storage write fails | Generation refused with 503, code `headline_options_storage`; never "assume zero" | Closed: cannot prove the cap |
| 67 | Post meta write of the set fails | Candidates are still returned to the browser; response carries `saved: false`; panel says the set will not reappear | Open: the editor still sees them |
| 68 | A custom rule throws | That rule is `na`; others run (case 48) | Open |
| 69 | Invalid UTF-8 in title or content from the browser | 400, code `headline_options_bad_input`; nothing scored | Closed |
| 70 | Post id does not exist or is in the trash | 404 | Closed |
| 71 | Browser sends content larger than the request limit (default 1 MB) | 413 before parsing | Closed |
| 72 | PHP time limit near | The library's deadline is set from the remaining request time less a margin (default 5 s); if under the minimum (default 8 s), refuse with 503 before calling | Closed |
| 73 | Editor closed mid-request | The call completes server side, the set is saved to meta (case 31), and counted | n/a |

Generation fails closed in every case because the cost of a wrong
headline is higher than the cost of no suggestion. Scoring fails open,
rule by rule, because it costs nothing and is advice.

### 4.9 Edge cases

74. Empty current headline: shown as "No headline yet", no score; closeness
    is not computed.
75. Headline with HTML entities as stored by WordPress (`&amp;`, `&#8217;`):
    decoded before scoring and display, re-escaped on output.
76. Right-to-left headlines (Arabic script): displayed with `dir="auto"`;
    length and rules unaffected.
77. Mixed scripts: an English headline over an English body that embeds a
    Hindi quote is English. The body's dominant script decides only when
    most of its letters, not a plurality, are in that script (from the
    script-share lessons).
78. Multisite: settings, caps, counters and the kill switch are per site.
    A network-level constant `HEADLINE_OPTIONS_DISABLED` still switches off
    every site.
79. A post being edited by another user (post lock held): generation and
    scoring work; "Use" works only in the current user's own editor state,
    as any typing would.
80. Revisions and autosaves: the plugin's meta is not revisioned, and the
    generate route ignores autosave posts (it resolves to the parent).
81. Content made only of a gallery, embed or video: after case 10a it is
    under the word minimum, so case 11 applies.
82. Very long titles pasted in by the user for scoring (over 1,000
    characters): rejected by the score route with 400; the panel says the
    text is too long to score.
83. Locale with a decimal comma: numbers in case 40 are compared as digit
    strings, so `3,5` in a German headline matches `3,5` in the text, not
    `35`. Grouping removal applies only to the patterns listed in case 40.

## 5. Public interface

Names here are this project's own and stable from 0.1.0.

### 5.1 REST API

Namespace `headline-options/v1`. Every route has a permission callback that
checks the use capability and `edit_post` for the post id.

| Route | Method | Body | Returns |
|---|---|---|---|
| `/posts/{id}/generate` | POST | `title`, `content`, `excerpt`, `count` (3 to 10, optional) | `set` (5.4), `notes` (list), `saved` (bool), `usage` (`site_today`, `site_cap`, `user_today`, `user_cap`) |
| `/posts/{id}/score` | POST | `headlines` (1 to 20 strings), `content` | list of score objects (5.4), same order |
| `/posts/{id}/sets` | GET | none | stored sets for the post, newest first |
| `/posts/{id}/choice` | POST | `set_id`, `index` | `recorded` (bool) |
| `/status` | GET | none | `enabled`, `configured`, `reason`, usage as above, `length_unit`, `script_check` (bool) |

Errors are `WP_Error` with the codes named in section 4 and the HTTP status
given there.

### 5.2 PHP functions

| Function | Returns | Notes |
|---|---|---|
| `headline_options_score(string $headline, string $content, array $context = [])` | `ScoreResult` | Context keys: `current`, `tags`, `categories`, `language`. No model call. |
| `headline_options_score_many(array $headlines, string $content, array $context = [])` | `ScoreResult[]` | |
| `headline_options_generate(int $post_id, array $state, array $args = [])` | `CandidateSet` | Same checks, caps and hooks as the REST route; throws typed exceptions. For CLI and tests. Never writes the title. |
| `headline_options_is_enabled()` | bool | Fresh read of switch, constant and key. |
| `headline_options_usage()` | array | Today's counts and caps. |

### 5.3 Classes and interfaces

- `Rule` interface: `id(): string`, `weight(): int`,
  `evaluate(Headline $h, Article $a): RuleResult`.
- `Headline`: the cleaned text, its length in the active unit, tokens,
  numbers found, entity candidates, dominant script (or null).
- `Article`: plain text, tags, categories, language, known entities,
  numbers found, content hash.
- `RuleResult`: `status` (`pass`, `warn`, `fail`, `na`), `earned`,
  `possible`, `ruleId`, `reason`, `data` (array, for example the matched
  phrase).
- `ScoreResult`: `headline`, `total` (?int), `band` (?string), `length`,
  `words`, `results` (RuleResult list), `closeness` (?float), `toArray()`.
- `CandidateSet`: `id` (UUID), `postId`, `createdAt` (UTC), `userId`,
  `contentHash`, `language`, `model`, `promptHash`, `candidates` (list of
  `Candidate`: `text`, `angle`, `score`), `requested`, `returned`, `notes`.
- `Cleaner`: the steps of case 17, usable alone.
- Exceptions, all extending `HeadlineOptionsException` with a stable
  `code()` matching the REST codes: `DisabledException`,
  `NotConfiguredException`, `TooShortException`, `BusyException`,
  `CapReachedException`, `MeterRefusedException`, `ProviderException`,
  `NoCandidatesException`, `StorageException`, `BadInputException`.

Every class except the WordPress glue is testable without WordPress.

### 5.4 JSON shapes

Score object: `{ "headline": string, "total": int|null, "band": "good"|"fair"|"weak"|null, "length": int, "length_unit": "grapheme"|"codepoint", "words": int, "closeness": number|null, "rules": [ { "id": string, "status": string, "earned": int, "possible": int, "reason": string } ] }`.

Set object: the `CandidateSet` fields in snake case, each candidate with its
score object.

### 5.5 Hooks

Actions:

| Action | Arguments | When |
|---|---|---|
| `headline_options_generated` | `CandidateSet $set` | After a successful generation, before the response |
| `headline_options_failed` | `int $post_id, string $code, string $message` | Any refused or failed generation |
| `headline_options_chosen` | `int $post_id, string $set_id, int $index, int $user_id` | After case 30 records a choice |
| `headline_options_cap_reached` | `string $scope ('site' or 'user'), int $count, int $cap` | First refusal of the day per scope |

Filters:

| Filter | Default | Purpose |
|---|---|---|
| `headline_options_post_types` | `['post']` merged with the setting | Where the panel appears |
| `headline_options_capability` | `edit_posts` | Use capability |
| `headline_options_manage_capability` | `manage_options` | Settings screen |
| `headline_options_post_language` | site locale | Language for prompt and rule lists |
| `headline_options_input_text` | the case 10 text | Change what the model sees |
| `headline_options_prompt` | built-in parts | Last word on system and user text |
| `headline_options_chain` | from settings | Supply an `llm-json-php` chain |
| `headline_options_rules` | built-in rules | Add, remove, replace rules (case 48) |
| `headline_options_weights` | section 7 | Rule weights |
| `headline_options_length_bounds` | section 7 | Pass and warn ranges |
| `headline_options_clickbait_terms` | `['en' => [...]]` | Phrase lists by language |
| `headline_options_number_words` | `['en' => [...]]` | Written numbers |
| `headline_options_known_entities` | `[]` | Extra entity names for case 42 |
| `headline_options_bands` | 75 and 50 | Band thresholds |
| `headline_options_daily_cap`, `headline_options_user_cap` | settings | Caps; can only be lowered by the filter, never raised above the setting |
| `headline_options_sets_kept` | 5 | Sets kept per post |

### 5.6 Prompt contract

The built-in prompt has a system part and a user part. The system part
asks for N headline candidates for a news or blog article, in the given
language and writing system, each a single line under the length upper
bound, each with a short angle label; tells the model to use only facts,
numbers and names present in the supplied text; to avoid clickbait, questions
the article does not answer, and emoji; and to return JSON only. The user
part carries the current headline, the excerpt, the article text and the
optional style note from settings, each in a clearly delimited block.

Output schema: `{ "candidates": [ { "headline": string, "angle": string } ], "entities": [ string ] }`,
`candidates` with 1 to 10 items, `headline` 1 to 300 characters, `angle`
0 to 80 characters; `entities` with 0 to 30 items of up to 80 characters
each, the names of people, places and organisations the candidates use,
written as in the article; no other keys. The `entities` key was added by
ruling 7 (`docs/ARCHITECTURE.md`): each listed name that appears in a
headline joins the entity candidates of case 42 and must appear in the
article, so a name the model invents and lists fails.

When `prompt-library` is active, the plugin registers a default template
`headline-options/generate` through its documented registration function,
and renders through the `promptlib_render` filter with variables
`count`, `language`, `max_length`, `current_title`, `excerpt`, `text`,
`style_note`, passing the built-in definition as the fallback. The output
schema is always the plugin's own; a template cannot change it.

### 5.7 WP-CLI

| Command | Effect |
|---|---|
| `wp headline-options status` | Switch, constant, key present, caps, today's counts, last error, length unit, script check |
| `wp headline-options score <headline> [--post=<id>] [--content-file=<file>] [--format=table\|json]` | Scores with no model call |
| `wp headline-options generate <post-id> [--count=<n>] [--format=table\|json]` | Generates from the saved post, counted against the site cap (user `cli`); prints candidates; never writes the title |
| `wp headline-options sets <post-id>` | Stored sets |
| `wp headline-options reset-usage [--yes]` | Zeroes today's counters |

Exit codes: 0 on success, 1 on error, 3 when disabled, 4 when capped.

### 5.8 Constants

| Constant | Effect |
|---|---|
| `HEADLINE_OPTIONS_DISABLED` | true forces generation off (case 6) |
| `HEADLINE_OPTIONS_PROVIDER_KEY` | Provider key; preferred over the settings field |
| `HEADLINE_OPTIONS_KEEP_DATA` | true keeps data on uninstall |

## 6. Data

| What | Where | Retention |
|---|---|---|
| Settings | option `headline_options_settings` (autoload on) | Until uninstall |
| Provider key (when not a constant) | option `headline_options_key` (autoload off) | Until uninstall |
| Day counters (site and per user) | option `headline_options_usage` (autoload off), keyed by local day | Previous days dropped on the first write of a new day |
| Rate limit | timestamps per user inside `headline_options_usage`, under the same lock row as the counters | Older than 60 s dropped |
| Counter lock | option row `headline_options_usage_lock` (autoload off), taken with `INSERT IGNORE`, value its expiry and an owner token | 10 s, deleted on release |
| Generation lock | option row `headline_options_lock_{id}` per post (autoload off), taken with `INSERT IGNORE`, value its expiry and an owner token | Expires (case 15), deleted on release |
| Last error | option `headline_options_last_error` (autoload off), code and time only, no content | Overwritten |
| Candidate sets | post meta `_headline_options_sets` (protected, not shown in custom fields, not in REST post responses), list of the last 5 sets | Oldest dropped; deleted with the post |
| Choices | inside the set: `chosen_index`, `chosen_by`, `chosen_at` | With the set |

Article text is not stored. The set stores the content hash only. Prompt
text is not stored; the prompt hash is. Uninstall deletes every option,
transient and post meta key above unless `HEADLINE_OPTIONS_KEEP_DATA` is
true. On multisite, uninstall runs for every site. Deactivation deletes
nothing.

## 7. Configuration and sensible defaults

Settings screen under Settings, "Headline options", for the manage
capability.

| Setting | Default | Range |
|---|---|---|
| Generation enabled | off on first install | on, off |
| Provider | OpenRouter | OpenRouter, OpenAI, OpenAI-compatible URL |
| Model chain | empty: the operator must choose | 1 to 3 model ids |
| Candidates per click | 5 | 3 to 10 |
| Site daily cap | 50 | 0 to 10,000 |
| Per-user daily cap | 15 | 0 to 1,000 |
| Per-user rate limit per minute | 4 | 1 to 30 |
| Input limit | 6,000 grapheme clusters | 1,000 to 30,000 |
| Minimum words | 40 | 10 to 500 |
| Post types | `post` | any type supporting `title` |
| Style note | empty | up to 500 characters, plain text |
| Confirm before Use | on | per user override in the panel |
| Length pass range | 30 to 70 | 10 to 200 |
| Length warn range | 20 to 90 | wider than pass |

Default weights (points possible): length 25, clickbait 20, numerals
support 20, entity support 20, withheld subject 5, shouting 5,
punctuation 5, emoji 5, numeral presence 5 (bonus counted only when
present), script match 10.

Generation is off on install so a site never spends money before an
administrator has chosen a model and caps.

## 8. Security and privacy

- Capability and `edit_post` checks on every route; nonces through the
  REST cookie flow; settings saved through the Settings API with its nonce.
- Every input validated: post id integer, `count` range, strings valid
  UTF-8 and within size limits, `headlines` list size.
- Every output escaped. Candidate text is model output and is treated as
  untrusted: rendered as text nodes in the panel, never as HTML.
- The provider key is never sent to the browser, never logged, and shown
  masked on the settings screen.
- Article text goes to the configured provider. The settings screen and the
  README say so in one plain sentence. No reader data, no user email and no
  author name is sent. The user id is not sent.
- Prompt injection inside the article ("ignore the above and...") can only
  change the candidates. Candidates are never applied without a click and
  are checked by the rules, so the blast radius is a bad suggestion.
- No `eval`, no shell, no remote code, no file writes.

## 9. Performance budget

- Editor load: the panel script is under 30 KB minified, loaded only on
  enabled post types, with no request until the panel is opened.
- Scoring 20 headlines against a 6,000-character article: under 50 ms on
  PHP 8.1 on a small server. No database query beyond reading settings and
  term names.
- Generation: one model call per click. Wall time is the provider's; the
  plugin's own work is under 100 ms.
- No front-end cost at all. Nothing loads on public pages.

## 10. Compatibility

- PHP 8.1, 8.2, 8.3. `ext-mbstring` required. `ext-intl` optional (grapheme
  length and NFC); behaviour without it is documented in case 36.
- WordPress 6.4 or later, block editor and classic editor (classic editor
  plugin or a post type without block support).
- Runtime dependency: `indianopinion/llm-json-php` (public, loaded as a VCS
  repository or bundled in the release zip). Optional: `script-share`,
  `spend-meter`, `prompt-library`, detected at runtime.
- Multisite: per site (case 78).
- Page caches and object caches: unaffected; all routes are authenticated
  and send `Cache-Control: no-store`.

## 11. Observability

- `/status` route and `wp headline-options status` show switch, caps,
  today's counts and the last error code with its time.
- Settings screen shows the same figures and a notice for credit exhaustion
  (case 59) and a missing library (case 62).
- Logging through a `headline_options_log` action (`level`, `message`,
  `context`) and, when `WP_DEBUG_LOG` is on, `error_log` lines prefixed
  `[headline-options]`. Logs carry post id, user id, codes, counts and
  durations. Never article text, headline text or the key.
- The actions in 5.5 let a site send its own metrics elsewhere.

## 12. Acceptance tests

Each test names the case it covers. PHP tests run under PHPUnit with a stub
WordPress layer and `llm-json-php`'s mock transport; editor tests run in a
JavaScript test runner against the panel component.

| Test | Case |
|---|---|
| T1 Panel registered for `post`, not for `page` by default | 1 |
| T2 User with `edit_posts` but not `edit_post` for this post gets 403 on generate and score | 2, 3 |
| T3 Request without nonce or app password gets 401 | 4 |
| T4 Setting off: generate returns 503 `headline_options_disabled`, mock transport receives no request; score still returns results | 5 |
| T5 Constant true and setting on: still disabled; settings screen field locked | 6 |
| T6 No key: generate 503 `headline_options_not_configured`; score works | 7 |
| T7 Saving, autosaving and publishing a post with the plugin active makes no model request (mock transport stays empty across `save_post`, `wp_insert_post`, `transition_post_status`) | 8 |
| T8 Generate uses the supplied unsaved title and content, not the saved post | 9 |
| T9 Content with shortcodes, blocks and entities becomes the expected plain text; shortcodes are not executed | 10 |
| T10 Input trimmed at a sentence end under the limit; Devanagari text counted in graphemes | 10 |
| T11 39 words refused 422 before any request and no cap counted; 40 words proceed | 11 |
| T12 Language filter value reaches the prompt; default is the site locale | 12 |
| T13 Rendered prompt asks for N candidates and contains no style note when empty | 13 |
| T14 Schema passed to the library matches 5.6 | 14 |
| T15 Second generate on the same post while the lock is held returns 409; lock expiry frees it | 15 |
| T16 Provider failure still increments site and user counters | 16, 58 |
| T17 Cleaner: list markers, wrapping quotes (each quote style), Markdown, tags, `Headline:` prefix, trailing full stop removed; `?` kept; ZWJ inside a Devanagari word kept | 17 |
| T18 Empty and duplicate candidates dropped; first duplicate kept | 18, 19 |
| T19 Candidate equal to the current headline dropped with a note | 20 |
| T20 Candidate over 200 graphemes dropped, not truncated | 21 |
| T21 Model returns 7 for N 5: 5 kept; returns 3: 3 shown with the note | 22 |
| T22 All candidates cleaned away: 502 `headline_options_no_candidates`, counted | 23 |
| T23 Angle cleaned and capped at 60; not part of the score | 24 |
| T24 Response order: current first, then by score, ties in model order | 25 |
| T25 Score object carries every rule with status, points and reason | 26 |
| T26 Panel "Use" dispatches a title edit to the editor store and no save; post stays dirty | 27, 32 |
| T27 "Use" over a different non-empty headline asks for confirmation; turning it off skips it | 28 |
| T28 Previous headline listed after "Use" and can be restored | 29 |
| T29 Choice route records index and user on the set; post title unchanged in the database | 30, 32 |
| T30 Reopening shows the last set with "earlier version" when the content hash differs | 31 |
| T31 No code path in the plugin calls `wp_update_post` or writes `post_title` (static test over the source plus a runtime spy) | 32 |
| T32 Score route accepts 20 headlines, rejects 21 with 400 | 33, 34 |
| T33 Same inputs give byte-identical score JSON across two runs | 35 |
| T34 Length bands at 19, 20, 29, 30, 70, 71, 90, 91; Hindi headline length in graphemes with intl and in code points less marks without; same result for plain Latin | 36 |
| T35 Clickbait: one phrase warn, two fail, curly apostrophe matches; language with no list is `na` | 37 |
| T36 Withheld subject warn on "This is why..." | 38 |
| T37 Numeral presence: none is `na`, one earns points | 39 |
| T38 Numeral support: `12` absent from text fails; `1,00,000` matches `100000`; Devanagari digits match ASCII; `3,5` does not match `35`; year rule | 40, 83 |
| T39 Entity support: capitalised run absent from text fails; possessive matches; tag name counts | 41 |
| T40 Devanagari headline with no Latin tokens and no tags: entity rule `na` | 42 |
| T41 Shouting: two caps words warn, acronym from text exempt, mostly caps fail | 43 |
| T42 Punctuation: `!!`, `?!`, ellipsis warn; trailing `?` not double counted | 44 |
| T43 Emoji and styled bold letters fail; rupee sign and degree sign pass | 45 |
| T44 Script match with script-share present: Hindi headline over English body fails; English headline over English body with a Hindi quote passes; without script-share the rule is `na` | 46, 77 |
| T45 Closeness reported and not part of the total | 47 |
| T46 A filter-added rule runs; a throwing rule becomes `na` and others still score | 48, 68 |
| T47 Lowest-scoring candidate still has an enabled "Use" button | 49 |
| T48 Site cap: request 51 of 50 refused 429 with reset time, no request sent | 50 |
| T49 User cap and rate limit codes and `Retry-After` | 51, 52 |
| T50 Two concurrent requests at cap minus one: exactly one passes (simulated with the storage layer) | 53 |
| T51 Cap 0 disables generation; empty cap setting falls back to default | 54 |
| T52 spend-meter present and denying: no request, 429 with its reason; allowed with reservation: record follows on success, release on a pre-call failure | 55 |
| T53 Plugin cap reached: spend-meter filter never called; newer contract version treated as absent with notice | 56 |
| T54 Credit exhausted: code and settings notice | 59 |
| T55 Schema failure and refusal codes | 60, 61 |
| T56 Library missing: generate disabled with notice; score works | 62 |
| T57 spend-meter unknown reason and throwing filter both deny | 63, 64 |
| T58 prompt-library error falls back to the built-in prompt | 65 |
| T59 Counter write failure refuses with 503 | 66 |
| T60 Meta write failure still returns candidates with `saved: false` | 67 |
| T61 Invalid UTF-8 400; trashed post 404; oversize body 413 | 69, 70, 71 |
| T62 Remaining time under the minimum refuses before the call | 72 |
| T63 Empty current headline: "No headline yet", no closeness | 74 |
| T64 Entities decoded before scoring and escaped on output | 75 |
| T65 Arabic-script headline rendered with `dir="auto"` | 76 |
| T66 Multisite: caps and switch per site; constant switches all off | 78 |
| T67 Autosave id resolves to the parent post | 80 |
| T68 Gallery-only content refused as too short | 81 |
| T69 Score text over 1,000 characters rejected 400 | 82 |
| T70 Uninstall removes every option, transient and meta key; keeps them with the constant | 6 (data) |
| T71 Logs and `/status` contain no article text, headline text or key | 8 (security) |
| T72 CLI `generate` exits 4 when capped and 3 when disabled; never changes the title | 5.7 |
| T73 Gen-queue present: generation still synchronous, no job enqueued | 57 |
| T74 Generation enabled is off on a fresh install | 7 (config) |
| T75 Editor closed mid-request: set saved and counted | 73 |
| T76 Post lock held by another user: generate and score still work | 79 |

## 13. Open questions

Answered by the builder in `docs/ARCHITECTURE.md`.

1. Length unit without `ext-intl`: is "code points less marks and format
   characters" close enough to graphemes for every script we support, or
   should the plugin ship a small grapheme break table instead?
2. `script-share` as a soft dependency (rule `na` when absent) or a hard
   Composer dependency bundled in the release zip, given the wave rule of
   no runtime dependencies beyond the permitted three?
3. Is `llm-json-php` acceptable as the one runtime dependency for this
   repository, which the wave plan files under group C, or must the
   provider call be written against the WordPress HTTP API directly?
4. Caps counted in generations (as specified) or in money through the
   library's usage figures when spend-meter is absent?
5. Candidate sets in post meta (as specified, last 5) or a small custom
   table, if sites want a history across posts for review?
6. Default clickbait and number-word lists: English only in 0.1.0, or seed
   short Hindi lists as an example of the per-language filter?
7. Entity rule in caseless scripts (case 42): is tags, categories and Latin
   tokens enough, or should the plugin match against any word of four or
   more letters that occurs once in the headline and never in the text?
8. Classic editor: full panel as a meta box, or a reduced version (score
   own headline plus generate, no restore list)?
9. Should "Use" also be offered for the SEO title field of common SEO
   plugins? The current answer is no (non-goal); confirm it stays out.

## 14. Lessons from production

General lessons, written as rules. They come from public sibling PRDs
(notably script-share for mixed-script text) and from plain WordPress
practice, not from any private pipeline.

14.1. Never write the headline on the editor's behalf. A suggestion lives in
the panel until a human clicks, and even then it lands in the unsaved editor
state, not the database.

14.2. Count length in what the reader sees. Graphemes, not bytes, not code
points with marks. Indic text is around a third combining marks.

14.3. Check every number and name in a candidate against the article. A
model writes a plausible figure as easily as a true one.

14.4. Compare numbers as digit strings after mapping native digits and
removing grouping. Do not parse them as floats; locales disagree on the
decimal mark.

14.5. "Not applicable" is its own answer. A rule that cannot run for a
language or script says so and leaves the total; it never passes by
default.

14.6. Check the headline and the body separately for script. A short
English headline over an English report that embeds a Hindi quote is
English. Let the body overrule only when most of its letters, not a
plurality, are in the other script.

14.7. Treat model output as untrusted text: clean it, cap it, render it as
text. Expect numbering, quotes, Markdown and a "Headline:" prefix.

14.8. Expect the model to echo the current headline and to repeat itself.
Remove both before showing a list.

14.9. Count a call against the cap when it is attempted. A failed call may
still be billed.

14.10. Keep the plugin's own cap and kill switch even when a site-wide
meter exists. The meter can only add refusals.

14.11. Fail generation closed and scoring open. A missing suggestion costs
nothing; a wrong headline costs a correction.

14.12. Ship with generation off. A plugin must never spend money before an
administrator has chosen a model and a cap.

14.13. Keep prompts generic and editable. House style belongs to the site,
in its own setting or prompt store, not in the plugin.

14.14. Never truncate a candidate to fit. A cut headline is a new headline
nobody wrote; drop it instead.
