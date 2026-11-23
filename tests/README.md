# Unit tests

Run the dependency-free unit suite with:

```sh
php tests/run.php
```

The suite covers pure validation and security helpers without opening a database connection. The XAMPP-provided PHPUnit command is obsolete and does not run on the installed PHP 8.2 runtime.

## Authentication integration tests

Start Apache and MySQL, then run:

```sh
php tests/integration-auth.php
```

The integration suite sends real HTTP requests through Apache and uses the configured MySQL database. It creates a uniquely named temporary account and verifies that the account is removed at the end. Set `SMART_GYM_BASE_URL` if the project is served at a different local URL.

Run the broader gym operations flow with:

```sh
php tests/integration-operations.php
```

It provisions test users, creates a plan and membership, records a payment, checks reception visits, and confirms that active members can check in and book classes. All fixture records are deleted when the run ends.

## UI HTTP smoke tests

With Apache running, run:

```sh
php tests/ui-smoke.php
```

This checks that the application pages return HTML with titles and viewport metadata, and that their local links and loaded assets resolve successfully. It is an HTTP smoke test; it does not replace visual browser testing.
