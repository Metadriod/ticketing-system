# IT Support Ticket API

Laravel backend for the supplied IT Support Ticket Management coding exercise.
Implemented in the existing Laravel application; no Vue project or authentication
is included. The API is ready for a separate frontend to consume.

## Requirements and local setup

- PHP 8.3+ with Composer and `pdo_sqlite` (this repository uses Laravel 13).
- SQLite is the default database; no external database service is needed.
- No npm install or frontend build is required for these API endpoints.

From the application directory in PowerShell:

```powershell
composer install
if (!(Test-Path .env)) { Copy-Item .env.example .env }
php artisan key:generate
```

Only generate the application key for a new local environment. Keep `.env` private.
Use `APP_ENV=local`, `DB_CONNECTION=sqlite`, and leave `DB_DATABASE` unset to use
`database/database.sqlite`. For a different SQLite file, use an absolute path.
Do not set `DB_URL` unless deliberately overriding the connection.

Before applying migrations, check the actual runtime target (this prints no credentials):

```powershell
php artisan tinker --execute='dump(["environment" => app()->environment(), "connection" => config("database.default"), "driver" => DB::connection()->getConfig("driver"), "host" => DB::connection()->getConfig("host"), "database" => DB::connection()->getDatabaseName(), "cached" => app()->configurationIsCached()]);'
```

For the default local setup, require environment `local`, connection/driver
`sqlite`, no host, and the absolute database path ending in
`ticketing-system/database/database.sqlite` (Windows backslashes are equivalent).
Resolve unexpected or cached configuration before proceeding. Back up an existing
SQLite file before modifying it. On a new database, create the file only if absent:

```powershell
if (!(Test-Path database/database.sqlite)) { New-Item database/database.sqlite -ItemType File }
```

The following command writes schema and migration history to that local database.
Under this workspace's safety rules, an agent must obtain explicit approval before
running it. It creates the skeleton's tables and the new `tickets` table; it does
not insert sample tickets. Do not use `migrate:fresh` on an existing database.

```powershell
php artisan migrate
php artisan serve
```

The API base URL is `http://127.0.0.1:8000/api`. For XAMPP hosting, configure the
document root to this application's `public` directory.

## Endpoint contract

The machine-readable contract is [`openapi.json`](openapi.json), which can be
imported into Postman. All responses, including errors, are JSON. Send
`Content-Type: application/json` for JSON bodies and preferably `Accept: application/json`.

- `GET /api/tickets`: 200 with `data`, pagination `links`, and `meta`.
- `POST /api/tickets`: 201 with `data` and a `Location` response header.
- `GET /api/tickets/{ticket}`: 200 with `data`, or 404.
- `PATCH /api/tickets/{ticket}`: 200 with updated `data`, or 404/422.
- `DELETE /api/tickets/{ticket}`: 200 with
  `{"message":"Ticket deleted successfully."}`, or 404.

Each ticket includes `id`, `title`, `description`, `category`, `priority`, `status`,
`created_at`, and `updated_at`. IDs and timestamps are server-managed.

Creation requires title (up to 255 characters), description (up to 10,000
characters), category, and priority. Whitespace-only required text is invalid.
Status is optional on creation and defaults to `open`.

- Category: `hardware`, `software`, `network`, `other`.
- Priority: `low`, `medium`, `high`, `critical`.
- Status: `open`, `in_progress`, `resolved`.

PATCH accepts any subset of the five editable fields; omitted fields keep their
values. Supplied fields cannot be null or blank. An empty PATCH object is a no-op.
Unknown properties are ignored, including attempts to set IDs and timestamps.

List parameters can be combined:

```text
GET /api/tickets?status=open&category=network&search=printer&per_page=20&page=1
```

Status and category are exact matches. Search is case-insensitive across title OR
description, grouped within the other filters. SQL LIKE wildcards `%` and `_` are
supported. Empty status/category/search values are ignored. Tickets are sorted by
descending ID. Page size defaults to 20 and accepts 1–100; page must be positive.
Invalid filters return 422 rather than silently ignoring invalid values.

An empty result is successful with `data: []` and `meta.total: 0`. Validation
failures return 422 with `message` and field-keyed arrays in `errors`. Unknown
ticket IDs return 404 with a JSON `message`.

## Example requests in PowerShell

These requests create, edit, and delete records. Run them only against your
intended local development database.

```powershell
$ticketPayload = @{
    title = 'Printer cannot connect'
    description = 'Office printer cannot connect to the network.'
    category = 'network'
    priority = 'high'
} | ConvertTo-Json

$createdTicket = Invoke-RestMethod -Method Post -Uri 'http://127.0.0.1:8000/api/tickets' -ContentType 'application/json' -Body $ticketPayload
$ticketId = $createdTicket.data.id
Invoke-RestMethod "http://127.0.0.1:8000/api/tickets/$ticketId"
Invoke-RestMethod 'http://127.0.0.1:8000/api/tickets?status=open&category=network&search=printer'
Invoke-RestMethod -Method Patch -Uri "http://127.0.0.1:8000/api/tickets/$ticketId" -ContentType 'application/json' -Body '{"status":"in_progress"}'
Invoke-RestMethod -Method Delete -Uri "http://127.0.0.1:8000/api/tickets/$ticketId"
```

## Tests and database isolation

First run the read-only runtime isolation check:

```powershell
php vendor/phpunit/phpunit/phpunit --filter TicketDatabaseSafetyTest --no-progress
```

It boots Laravel using the actual PHPUnit configuration and checks environment,
cached configuration, and the resolved connection without executing SQL. Both this
check and each ticket API test abort unless the environment is `testing`, the
connection/driver is `sqlite`, the database is exactly `:memory:`, `DB_URL` is
empty, and configuration is uncached. An in-memory database is disposable and
does not touch `database/database.sqlite` or any external database.

After explicit approval for database-writing tests:

```powershell
php vendor/phpunit/phpunit/phpunit --filter TicketApiTest --no-progress
```

Each test checks isolation before running migrations. Tests create, read, update,
and delete synthetic tickets in the in-memory database; closing the test
application discards its connection and data. No persistent database backup or
restoration is needed. Coverage includes required fields and enums, persistence,
partial/full updates, combined and independent filters, pagination, empty lists,
invalid filters, JSON errors without an Accept header, missing IDs, deletion, and
server-managed fields.

Non-database checks:

```powershell
php artisan route:list --path=api
php vendor/laravel/pint/builds/pint --test app routes/api.php database/factories/TicketFactory.php database/migrations/2026_10_22_000001_create_tickets_table.php tests/Support tests/Feature/TicketApiTest.php tests/Unit/TicketDatabaseSafetyTest.php
```

## Scope and submission notes

- Authentication is excluded by the exercise. This API allows unauthenticated CRUD.
- No frontend, frontend tests, or Vue tooling was added, per the requested backend-first scope.
- No seed command runs automatically. `TicketFactory` is available for isolated tests.
- The development database migration must be approved and applied separately.
- The supplied folder had no `.git` directory. No Git history, commits, pushes,
  submission archive, or email delivery was created. Before final submission,
  initialize Git if needed, use a feature branch, and make meaningful commits.
- Never commit `.env`, private database files, or credentials.
- Laravel Boost is installed as a development dependency as required by the workspace setup instructions.
