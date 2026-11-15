# Contributing

Thanks for helping. Bug reports, fixes and new language lists are all welcome.

## Setup

PHP 8.1 or later with mbstring (intl recommended), Composer 2, and Node 20 or
later for the editor tests.

```sh
git clone https://github.com/IndianOpinion/headline-options.git
cd headline-options
composer install
```

The sibling packages (`llm-json-php`, `script-share`) are fetched from GitHub as
VCS repositories. If GitHub rate-limits anonymous API calls, set a token in
`COMPOSER_AUTH` for that shell.

## Tests and checks

```sh
composer test                     # PHPUnit, with a stub WordPress layer
composer analyse                  # PHPStan level 8
composer lint                     # phpcs, PSR-12
node --test tests/js/*.test.js    # editor logic
```

All four must pass before a pull request is merged. No WordPress install or
network is needed: the tests use `tests/stubs/` and llm-json-php's mock
transport.

## Style

- PSR-12, `declare(strict_types=1);` in every PHP file, PHP 8.1 syntax only
  (no readonly classes, DNF types or `true` type).
- Escape every output, validate every input, no `@`, no `eval`, no shell.
- Prose: short sentences, plain words, British spelling, no em dashes.
- Never add a code path that writes a post title or calls a model without a
  click. The tests check both.

## Pull requests

- One change per pull request, with a test that fails without it.
- Update `CHANGELOG.md` under Unreleased and the README when behaviour or
  options change.
- New rules or lists: say where the list comes from and add tests for a
  match and a near miss.
- A sign-off line is not required.
