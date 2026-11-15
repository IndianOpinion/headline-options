=== Headline options ===
Contributors: indianopinion
Tags: headlines, editor, newsroom, llm, writing
Requires at least: 6.4
Tested up to: 6.9
Requires PHP: 8.1
Stable tag: 0.1.0
License: MIT
License URI: https://opensource.org/licenses/MIT

On a click, asks a language model for several headline candidates, scores them with plain rules, and shows them in the editor. Never replaces a headline on its own.

== Description ==

Written from scratch; not derived from any third-party plugin.

An editor clicks "Suggest headlines" in the post editor. The plugin sends the article as it is on screen to the model provider the site administrator configured, cleans the candidates that come back, and scores each one and the current headline with transparent rules: length in graphemes, clickbait phrases, a withheld subject, numbers that must appear in the article, names that must appear in the article, shouting, loud punctuation, emoji and the writing system. The editor sees every score with a reason for each rule.

"Use" places a candidate in the title field of the editor only. The post is not saved until the editor saves it. Nothing runs on save, publish, import or a schedule.

Spend is bounded by a site daily cap, a per-user daily cap, a per-user rate limit and a kill switch, all in the plugin's own settings. Generation is off on install.

Privacy: when an editor clicks "Suggest headlines", the article text, the current headline, the excerpt and the style note are sent to the configured provider. No reader data, user email, author name or user id is sent.

This project came out of the work on indianopinion.org and is written from scratch; it is independent of and not affiliated with any third-party plugin it integrates with.

== Installation ==

1. Copy the plugin folder to wp-content/plugins/headline-options/ and run `composer install --no-dev` in it (or place indianopinion/llm-json-php in lib/llm-json-php/).
2. Activate the plugin.
3. Under Settings, Headline options, choose a provider, add a key and one to three model ids, check the caps, and turn generation on.

== Frequently Asked Questions ==

= Does it ever change a headline by itself? =

No. Suggestions need a click, and "Use" only edits the unsaved editor state.

= Does it work without the model library? =

Scoring works. Generation needs indianopinion/llm-json-php or a caller supplied through the headline_options_caller filter.

= Which languages does it support? =

Length, numbers and the script check work in every script. The clickbait, withheld-subject and number-word lists are English in 0.1.0 and can be extended through filters.

== Changelog ==

= 0.1.0 =
* First release.
