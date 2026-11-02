# Questions API

The versioned API is available under `/api/v1` and is restricted to administrator accounts.

## Input format

All API write endpoints accept `application/json`. Do not send Excel, CSV, multipart form data, or uploaded files to `/api/v1`. Excel and CSV uploads are available only through the browser interface.

## Authentication

Use HTTP Basic authentication with an administrator email address and password. Always call the API over HTTPS outside local development.

```bash
curl --user 'admin@example.com:password' \
  -H 'Accept: application/json' \
  https://example.com/api/v1/imports
```

Unauthenticated requests return `401`. Authenticated non-admin users return `403`. Validation failures return `422` with Laravel's standard `message` and `errors` fields.

## Imports

### List imports

`GET /api/v1/imports`

Query parameters:

| Parameter | Description |
| --- | --- |
| `search` | Optional import display-name search, maximum 255 characters. |
| `per_page` | Results per page, from 1 to 100. Defaults to 25. |
| `page` | Page number. |

The response uses Laravel pagination fields, including `data`, `current_page`, `last_page`, `per_page`, and `total`.

### Create or upsert an import

`POST /api/v1/imports`

Send JSON containing a stable `import_key` and the transaction rows. No file is sent to the API. Reuse the same `import_key` when updating or retrying the same batch.

```bash
curl --user 'admin@example.com:password' \
  -X POST \
  -H 'Accept: application/json' \
  -H 'Content-Type: application/json' \
  --data '{
    "import_key": "ledger-2026-10",
    "file_name": "October ledger",
    "rows": [{
      "txn_id": "TXN-001",
      "location": "Main Store",
      "bank_name": "Local Bank",
      "transaction_date": "2026-10-01",
      "check_no": null,
      "paid_to_by": "Supplier",
      "account": "Office Supplies",
      "credit": 123.45,
      "debit": null,
      "description": "Supplies",
      "class_name": "Central"
    }]
  }' \
  https://example.com/api/v1/imports
```

Required top-level fields:

| Field | Description |
| --- | --- |
| `import_key` | Stable external batch identifier, maximum 255 characters. |
| `file_name` | Optional import display name, maximum 255 characters. Defaults to `import_key`. The field retains its legacy name in responses. |
| `rows` | Between 1 and 20,000 transaction objects. |

Transaction fields:

| Field | Required | Description |
| --- | --- | --- |
| `txn_id` | Yes | Unique transaction identifier within the submitted batch, maximum 255 characters. |
| `location` | Yes | Location name, maximum 255 characters. |
| `bank_name` | Yes | Bank name, maximum 255 characters. |
| `transaction_date` | Yes | `YYYY-MM-DD` or `MM/DD/YYYY`. |
| `check_no` | No | Check/reference number, maximum 255 characters. |
| `paid_to_by` | Yes | Paid-to/by value, maximum 255 characters. |
| `account` | Yes | Account value, maximum 255 characters. |
| `credit` | Conditional | Non-negative number. Provide exactly one of `credit` or `debit`. |
| `debit` | Conditional | Non-negative number. Provide exactly one of `credit` or `debit`. |
| `description` | Yes | Transaction description, maximum 10,000 characters. |
| `class_name` | Yes | Class name, maximum 255 characters. |
| `sheet_name` | No | Optional source grouping label, maximum 255 characters. Defaults to `API`. |

The API hashes `import_key` to identify the import. Rows are upserted inside that import, so resending the same batch updates the transaction text without replacing answer-related fields.

A new import returns `201` and `created: true`. An existing import returns `200` and `created: false`.

```json
{
  "data": {
    "id": 12,
    "file_name": "October ledger",
    "sha256": "...",
    "row_count": 250
  },
  "created": true,
  "message": "250 rows imported."
}
```

### Get an import

`GET /api/v1/imports/{id}`

Returns the import metadata in `data`, or `404` when it does not exist.

### List an import's rows

`GET /api/v1/imports/{id}/entries`

Supports `per_page` from 1 to 100 and the standard `page` parameter. Rows are ordered by source grouping (`sheet_name`) and submission order (`row_number`).

### Rename an import

`PATCH /api/v1/imports/{id}`

```json
{
  "file_name": "October ledger — revised"
}
```

The name is required and may contain up to 255 characters.

### Delete an import

`DELETE /api/v1/imports/{id}`

Returns `204`. Deleting an import also permanently deletes all of its imported accounting entries, answers, attached detail files, and related entry data.

## All accounting data

`GET /api/v1/accounting-entries`

This endpoint returns complete imported accounting rows together with their current answer and import metadata. Each result includes the transaction ID (`txn_id`), location, bank, date, check number, paid-to/by value, account, credit, debit, description, class, answer and answer status fields, archive fields, import display name and hash, uploader ID, import time, and answering user's name and email.

Query parameters:

| Parameter | Description |
| --- | --- |
| `import_id` | Exact import ID. |
| `location` | Exact location. |
| `txn_id` | Exact transaction ID. |
| `answered` | `true`/`1` for answered rows or `false`/`0` for pending rows. |
| `search` | Searches transaction ID, location, bank, check number, paid-to/by, account, description, answer, and import display name. |
| `per_page` | Results per page, from 1 to 100. Defaults to 25. |
| `page` | Page number. |

```bash
curl --user 'admin@example.com:password' \
  -H 'Accept: application/json' \
  'https://example.com/api/v1/accounting-entries?location=Main%20Store&answered=1&per_page=50'
```

Example result:

```json
{
  "data": [{
    "id": 41,
    "import_id": 12,
    "txn_id": "TXN-001",
    "location": "Main Store",
    "bank_name": "Local Bank",
    "transaction_date": "2026-10-01",
    "check_no": null,
    "paid_to_by": "Supplier",
    "account": "Office Supplies",
    "credit": "123.45",
    "debit": null,
    "description": "Supplies",
    "class_name": "Central",
    "answer": "Approved",
    "answered_by": 3,
    "answered_at": "2026-11-03 12:00:00",
    "import_file_name": "October ledger",
    "import_sha256": "...",
    "import_uploaded_by": 1,
    "imported_at": "2026-11-03 11:00:00",
    "answered_by_name": "Admin User",
    "answered_by_email": "admin@example.com"
  }],
  "current_page": 1,
  "per_page": 25,
  "total": 1
}
```

## Accounts and payees

Accounts and payees are represented as location-specific question options. The `type` field is either `account` or `paid_to_by`.

### List options

`GET /api/v1/question-options`

Query parameters:

| Parameter | Description |
| --- | --- |
| `location` | Exact location filter. |
| `type` | `account` or `paid_to_by`. |
| `search` | Optional value search, maximum 255 characters. |
| `per_page` | Results per page, from 1 to 100. Defaults to 25. |
| `page` | Page number. |

### Create or upsert an option

`POST /api/v1/question-options`

```json
{
  "location": "Main Store",
  "type": "account",
  "value": "Office Supplies"
}
```

The location must already exist in imported accounting entries. The option is upserted by location, type, and value. A new option returns `201` and `created: true`; an existing option returns `200` and `created: false`.

### Get an option

`GET /api/v1/question-options/{id}`

Returns the option in `data`, or `404` when it does not exist.

### Update an option

`PATCH /api/v1/question-options/{id}`

```json
{
  "value": "Technology Expense"
}
```

The value is required, may contain up to 255 characters, and must be unique for the option's location and type.

### Delete an option

`DELETE /api/v1/question-options/{id}`

Returns `204`. The option is removed only from its location.

### Bulk upsert accounts and payees

`POST /api/v1/question-options/import`

Send JSON arrays. No file is sent to this endpoint.

```bash
curl --user 'admin@example.com:password' \
  -X POST \
  -H 'Accept: application/json' \
  -H 'Content-Type: application/json' \
  --data '{
    "locations": ["Main Store", "North Store"],
    "accounts": ["Office Supplies", "Travel Expense"],
    "paid_to_by": ["Preferred Supplier", "Local Vendor"]
  }' \
  https://example.com/api/v1/question-options/import
```

`locations` is required. Provide at least one value in `accounts` or `paid_to_by`. Options are upserted by location, type, and value, so repeated requests update timestamps without creating duplicates.

## Audit logging

API creates, upserts, updates, bulk operations, and deletes are recorded in Activity logs with the authenticated administrator, action, subject, and affected values.

## Status codes

| Status | Meaning |
| --- | --- |
| `200` | Successful read, update, or idempotent upsert. |
| `201` | New import or option created. |
| `204` | Resource deleted successfully. |
| `401` | Basic authentication is missing or invalid. |
| `403` | The authenticated account is not an administrator. |
| `404` | The requested resource does not exist. |
| `422` | Request validation failed. |
