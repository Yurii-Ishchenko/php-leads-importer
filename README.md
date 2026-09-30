# PHP Leads Importer

Laravel application for asynchronous XLSX leads import into MySQL.

The uploaded file is processed in the background using Laravel Queue. Import progress is displayed on the page without reloading.

## Stack

- PHP 8.3
- Laravel 12
- MySQL 8.4
- OpenSpout
- Laravel Database Queue
- Docker Compose

## Installation

Create the environment file:

```bash
cp .env.example .env
```

Build the application:

```bash
docker compose build app
```

Install dependencies:

```bash
docker compose run --rm --no-deps app composer install
```

Start the containers:

```bash
docker compose up -d
```

Run database migrations:

```bash
docker compose exec app php artisan migrate
```

Application:

http://localhost:8080

## How it works

1. XLSX file is uploaded and saved on the server.
2. Laravel creates an import record and dispatches a queue job.
3. The HTTP request returns `202 Accepted`.
4. A separate queue worker processes the file.
5. Rows are inserted into MySQL in batches of 500.
6. The page displays the current import progress.

The XLSX processing runs in a CLI queue worker, so the standard HTTP `max_execution_time=30` does not limit the import process.

## XLSX format

Expected columns:

```text
external_id
created_at
first_name
last_name
phone
email
city
source
utm_campaign
product
budget_uah
status
manager
comment
next_contact_at
```

`external_id` is required. Duplicate `external_id` values are allowed.

## Database

Database structure is defined by Laravel migrations located in:

```text
database/migrations/
```

No SQL dump is required.

## Tests

Run tests:

```bash
docker compose run --rm --no-deps app php artisan test
```

Check code style:

```bash
docker compose run --rm --no-deps app vendor/bin/pint --test
```

## Stop

```bash
docker compose down
```
