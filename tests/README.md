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
and have `pdo_sqlite` enabled. The devcontainer installs that extension.
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

`config/packages/test/doctrine.yaml` forces a fresh SQLite in-memory database for
each kernel. The fixture helper checks that the connection is in memory before
creating a schema from entity metadata with Doctrine's `SchemaTool`. It never
purges an external database. Application tests disable kernel reboot so multiple
requests share that test's database; separate tests boot separate kernels.
There is no external service or pre-existing schema to prepare.

SQLite verifies the ORM persistence path, but does not reproduce all MariaDB or
PostgreSQL types, lengths, transaction semantics or concurrent requests. Production
schema changes should still use migrations and be checked against the deployment
database. There are currently no migration classes in this repository.

## Defects corrected while adding regression tests

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
