# WP Term Order contributor guidance

## Compatibility

- Preserve PHP 7.4 and WordPress 6.4 compatibility unless a dedicated pull
  request explicitly changes the published minimums.
- Preserve both supported storage strategies: the default `term_taxonomy`
  `order` column and the opt-in term-metadata strategy.
- Treat ordering behavior as compatibility-sensitive. The plugin intentionally
  supplies its order when WordPress presents the default term-name ordering;
  do not change that behavior without dedicated regression coverage and a
  maintainer decision.
- Preserve the public class methods, filters, action hooks, registered term
  metadata, AJAX action, and database migration path.

## Tests

- Add or update a regression test before changing observed PHP behavior.
- Exercise both database strategies when touching persistence or queries.
- Exercise unsupported taxonomies and filter overrides when changing taxonomy
  selection.
- Run `composer test`, the declared PHP syntax matrix, and metadata/artifact
  validation before requesting review.

## Releases

The source version, readme stable tag, Git tag, and WordPress.org version must
agree before publishing. A release requires explicit authorization and must use
the protected WordPress.org environment.

## Automation

Follow the organization-level safety boundaries. AI-authored implementation
must remain a draft pull request and cannot modify workflows, release policy,
ownership, security policy, dependencies, or this file without a specific
maintainer decision for that change.
