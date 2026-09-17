# Contributing

Thanks for helping maintain WP Term Order.

## Before changing behavior

Describe the observable behavior, affected taxonomies and storage strategy,
compatibility expectations, and acceptance criteria in a GitHub issue. Report
suspected vulnerabilities privately through
[GitHub Security Advisories](https://github.com/stuttter/wp-term-order/security/advisories/new).

## Pull requests

- Keep each pull request focused and reversible.
- Add regression coverage for behavior changes and bug fixes.
- Preserve the declared PHP and WordPress minimum versions.
- Exercise both the database-column and term-metadata strategies when changing
  persistence or query behavior.
- Preserve explicitly requested ordering and taxonomy opt-out filters.
- Identify stored-data, database-schema, dependency, automation, and release
  implications where applicable.
- Do not commit credentials, dependency directories, caches, databases, or
  generated release ZIP files.
- Run `composer test` before requesting review.
- Wait for every required check and resolve review conversations before merge.

AI-assisted contributions are welcome, but the contributor remains responsible
for understanding and validating the result.

## Development requirements

The plugin and its Composer development toolchain require PHP 7.4 or newer.
Install the locked dependencies with `composer install`, then run the test suite
with `composer test`.
