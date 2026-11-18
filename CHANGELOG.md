# Changelog

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project
uses [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.1.0] - 2026-11-18

### Added

- "Suggest headlines" in the block editor panel and a reduced classic editor
  meta box: one model call per click through llm-json-php with a fixed JSON
  schema, or through a caller supplied by the `headline_options_caller`
  filter.
- Ten deterministic scoring rules: length in graphemes, clickbait phrases,
  withheld subject, numeral presence and support, entity support, shouting,
  punctuation, emoji, and script match (with script-share).
- Candidate cleaning: list markers, quotes, Markdown, HTML, label prefixes,
  controls; duplicates, the current headline and over-long lines dropped.
- "Use" edits the unsaved editor title only, with confirmation, a Previous
  list and a recorded choice. Free scoring of any typed headline.
- Site and per-user daily caps, a per-user rate limit, atomic counters, a
  per-post lock, the kill switch and `HEADLINE_OPTIONS_DISABLED`.
- spend-meter (contract 1) and prompt-library integration through their
  hooks.
- REST routes, PHP functions, actions and filters, and WP-CLI commands.
- Uninstall that removes every option, lock row, transient and meta key.
