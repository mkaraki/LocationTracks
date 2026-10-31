# Testing and ingestion review

The project uses Symfony 8.1, Doctrine ORM 3.7 and PHPUnit 13.4. The suite follows
[Symfony's testing guide](https://symfony.com/doc/current/testing.html):

```console
php bin/phpunit
php bin/phpunit --testsuite Unit
php bin/phpunit --testsuite Integration
php bin/phpunit --testsuite Application
```

Run `composer install` first. PHP must satisfy Composer's platform requirements
and have `pdo_mysql` enabled. The devcontainer already installs that extension.
`symfony/test-pack` was installed through Composer; Flex unpacked it into the
existing PHPUnit, BrowserKit and CSS Selector requirements.

* Unit tests call the location parser directly with a mocked entity manager.
  They check field conversion, UTC timestamps, optional fields, metadata,
  invalid/missing coordinates, boundary values and flush control.
* Integration tests extend `KernelTestCase`, obtain the parser from the service
  container, persist to a real database, clear Doctrine's identity map and reload
  through repositories. They check relationships, JSON metadata, hashes,
  timestamps and source password hashing.
* Application tests extend `WebTestCase` and send HTTP requests through routing,
  the real security firewalls, controllers and Doctrine. They check Basic auth,
  malformed input, valid and invalid locations, non-location records, nested
  canonicalization, duplicates, list order, route methods and form login/CSRF.
  Source-management tests also cover owner-only listings, source creation and
  input validation, tampered forms, disable authorization/CSRF, repeated disables,
  credential revocation and preservation of historical records. Unit tests check
  role removal on disabled sources; an integration test exercises the real
  security user provider after credentials are cleared.
* `/points/` application tests exercise anonymous and ingestion-only access,
  empty results, multiple owners and sources, date/coordinate/measurement
  rendering, missing versus zero measurements, disabled-source history,
  descending ID order, 100-row cursor pagination in both directions, cross-owner
  cursor rejection and malformed cursor input. Fixtures include 101 owned points
  interleaved with another owner's points, so page limits and ownership are
  checked together.

## MariaDB 10.6 setup

Start the dedicated test server before running integration/application tests:

```console
docker compose -f compose.test.yaml up -d --wait
php bin/console doctrine:migrations:migrate --env=test --no-interaction
php bin/phpunit
docker compose -f compose.test.yaml down
```

The test container exposes port 3307 on localhost and creates
`location_tracks_test` with the test-only credentials `test` / `test`. It has no
volume shared with the development server. Unit tests need no database and can
be run independently with `php bin/phpunit --testsuite Unit`.

Tests use the standard `DATABASE_URL` through `config/packages/doctrine.yaml`.
The committed default in `.env.test` connects to the dedicated test container.
To use another MariaDB 10.6 server, override `DATABASE_URL` in `.env.test.local`
or the process environment (`.env.local` is ignored in the test environment):

```dotenv
DATABASE_URL="mysql://test:test@127.0.0.1:3307/location_tracks?serverVersion=10.6.27-MariaDB&charset=utf8mb4"
```

The URL contains the **base** name `location_tracks`; the existing Doctrine
configuration appends `_test` and optional `TEST_TOKEN`. The resulting database
must exist, and its user needs permission to create/drop its tables. Parallel
runs must use different `TEST_TOKEN` values and pre-create those databases.

The existing devcontainer exports `DATABASE_URL` for its development database,
which takes precedence over `.env.test`. Override it in the process environment
when testing, using the desired base name and `db:3306` with that server's
credentials. Run
`php bin/console doctrine:database:create --env=test --if-not-exists` once to
create `location_tracks_test`. Do not point this URL at the application database.

Before each database test, the fixture helper verifies the `test` environment,
then drops/recreates the mapped entity tables with Doctrine's `SchemaTool`.
The connection uses `DATABASE_URL` and Doctrine's configured test suffix.
Application
tests keep a single kernel across requests. Tests run sequentially against this
database; repeated full runs reset their fixtures. SQLite is not used.

The initial Doctrine migration creates the schema, including the explicit
64-byte binary length needed for the SHA-256 hex payload hash on MariaDB. CI
applies migrations to its fresh service before running the tests; per-test
fixture resets use ORM metadata. Existing deployments with tables created before
migrations were added need their migration baseline reconciled before running
this initial migration. These tests do not exercise concurrent requests.

## GitHub Actions

[The workflow](../.github/workflows/tests.yaml) runs on pushes to every branch
and pull requests targeting `master`. It installs dependencies on PHP 8.5,
starts a healthy `mariadb:10.6` service, applies migrations, validates configuration, then
runs all three test suites. The CI database user can access only the dedicated
`location_tracks_test` database; no repository secrets are required.

The trigger filters follow
[GitHub's workflow syntax](https://docs.github.com/en/actions/reference/workflows-and-actions/workflow-syntax).

## Defects corrected while adding regression tests

* Populated `/points/` pages returned HTTP 500 because Twig attempted to print a
  date object directly. The table now formats the actual `recordedAt` timestamp
  as UTC, instead of falling back to ingestion time through nonexistent
  `reportedAt`. It also uses the correct `longitude`, `accuracyM` and `altitudeM`
  accessors, so coordinates and optional measurements display correctly. Dates
  include a machine-readable `<time datetime>` value and an explicit UTC heading.
* A leftover SQLite override prevented the standard `DATABASE_URL` from selecting
  MariaDB in tests and CI. That override was removed; the fixture helper checks
  the actual database name and server again before resetting tables.
* Source creation accepted blank names and values longer than MariaDB's name and
  username columns, causing database errors instead of form validation errors.
  These fields now have server-side constraints and HTTP regression tests.
* MariaDB schema generation failed because the binary `payload_hash` column
  lacked a required length. Its mapping now specifies 64 bytes, matching the
  SHA-256 hex strings written by ingestion. SQLite had not detected this defect.
* `LocationSource` referenced `PasswordAuthenticatedUserInterface` without its
  import, preventing the kernel from compiling.
* Non-location messages never set the non-null `RawRecord.parse_status` column.
  Ingestion now initializes it to `pending`; parsed locations overwrite it.
* `/login` was covered by the catch-all `ROLE_USER` access rule, preventing
  anonymous access. It now has a preceding `PUBLIC_ACCESS` rule. CSRF protection
  is explicitly enabled, matching the authenticator and login template.
* The old login test called nonexistent `User::setPassword()`, deleted existing
  database users and followed the success redirect to a home route that does not
  exist. Its replacement uses isolated fixtures, `setPasswordHash()` and checks
  the authenticated session on `/login`.

## Outstanding review findings

* **New source settings: authentication usernames are not unique.**
  The new-source form and `LocationSource.auth_username` mapping allow an existing
  username to be registered again, including one belonging to another owner.
  The security provider looks up only that username, so sources with the same
  username cannot be authenticated reliably. Add a form/`UniqueEntity` check and
  a database unique constraint through a migration (nullable usernames allow
  multiple disabled sources). Existing duplicates must be resolved first.

1. **High: deduplication crosses source/user boundaries.**
   `RawRecordController::ingest_owntracks()` looks up only `payload_hash`. If two
   authenticated sources submit identical JSON, the second source's record is
   discarded. Include source identity in the duplicate lookup and database
   uniqueness rule; add a two-source HTTP regression when making that change.
2. **High: duplicate ingestion is not concurrency-safe.**
   The lookup followed by insertion has no unique database constraint or lock.
   Simultaneous requests can both insert a raw record and a location point. Use
   a migration for a source/hash unique constraint and handle unique violations
   as successful retries (or use Symfony Lock to serialize ingestion).
3. **Medium: decoding loses JSON object identity.**
   Associative decoding turns `{}` into `[]`; the root object is then rejected,
   and nested empty objects or objects with sequential numeric keys can be
   canonicalized as lists. This changes the stored payload and may deduplicate
   distinct messages. Preserve object/list identity when moving request binding
   to Serializer/Validator and `MapRequestPayload`.
4. **Medium: user/source relationship helpers are incomplete.**
   `User::$locationSources` is never initialized, and `removeLocationSource()`
   never removes the entry from its collection. The inverse mapping on `User`
   also has no matching `inversedBy` on `LocationSource::$user`. Fix these before
   building source-management screens and add entity/ORM regression tests.
5. **Maintainability: parsing lives in a controller instantiated manually.**
   `RawRecordController` creates `LocationPointController` with `new`, bypassing
   service injection. Move parsing into an autowired service and retain these
   behavioral tests. Request parsing currently uses manual `json_decode()`;
   the repository conventions call for Serializer/Validator request DTOs.

The suite covers sequential submissions. It does not assert that the outstanding
cross-source or concurrent duplicate behavior is desirable.
