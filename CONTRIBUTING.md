# Contributing

Bug reports, compatibility fixtures, performance measurements, documentation,
and pull requests are welcome. Changes should remain focused on OPC packaging;
WordprocessingML, SpreadsheetML, and PresentationML domain models belong in
specialized packages.

## Before opening an issue

- Test with the latest stable release.
- Reduce failures to the smallest reproducible package when possible.
- For incompatible files, include the producing application and version. Attach
  a file only when it contains no private information.
- For performance reports, include PHP version, operating system, package size,
  largest part size, and the exact benchmark command.

Report security issues privately as described in [SECURITY.md](SECURITY.md).

## Development setup

```shell
composer install
composer check
```

`composer check` validates Composer metadata and PHP syntax, checks formatting,
runs PHPStan at level `max` with strict rules, and executes PHPUnit. Use
`composer format` to apply the project style. Do not introduce PHPStan baselines
or ignored errors instead of fixing the underlying type problem.

Run the package benchmark with:

```shell
composer benchmark
```

Compare single-part deletion loops with the batch removal helpers (three-run
medians, with construction excluded):

```shell
XDEBUG_MODE=off php tools/benchmark-removal.php
```

Run the optional LibreOffice interoperability test with:

```shell
SOFFICE=/path/to/soffice vendor/bin/phpunit tests/LibreOfficeInteropTest.php
```

## Tests

Add focused unit tests for new behavior and an integration test when a change
affects serialized OPC packages. Security-sensitive changes should include a
negative test proving malformed input is rejected before expensive processing.

## Pull requests

Keep every pull request focused and include tests for behavior changes. Changes
to lazy loading, ZIP reads/writes, temporary storage, hashing, or encryption must
include before/after measurements when they can affect performance.

Use a descriptive branch prefix:

- `feature/` for new behavior;
- `fix/` for correctness fixes;
- `perf/` for performance work;
- `test/` for test-only changes;
- `docs/` for documentation;
- `ci/` for automation and tooling;
- `refactor/` for internal changes without an API change.

Pull requests run tests on PHP 8.1 through 8.5, lowest supported dependencies,
and Windows. Merge only after all required checks pass.

## Public API

Keep ZIP details inside `Internal`. New public APIs should use OPC vocabulary,
remain compatible with PHP 8.1+, and be documented on the relevant page in
`docs/`. Keep the README focused on installation, a working quick start, and
documentation navigation.
Backward-incompatible changes require an explicit release decision.

Document stream ownership explicitly. A method must state whether it consumes
the current position or the complete resource, who closes it, and how long the
resource must remain open.
