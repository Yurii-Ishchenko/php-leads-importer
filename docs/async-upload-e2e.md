# Asynchronous upload browser verification

Checked on 30 September 2026 using the running Laravel application, Nginx,
MySQL and database queue worker. The frontend uses the existing Blade views
and `public/js/imports.js`; no frontend dependencies or build tools were added.

## Request flow

The browser submits the selected file through `FormData` and vanilla `fetch()`.
`POST /imports` saves the upload, dispatches the existing database queue job and
returns HTTP 202 JSON without a `Location` header. JSON includes `id`, `status`,
`processed_rows`, `total_rows` and `status_url`.

The frontend immediately requests that existing import URL with
`Accept: application/json`. It schedules the next poll three seconds after a
response, preventing overlapping requests. The same URL still serves the Blade
results page when opened normally. Both pages update progress without reloads;
the previous meta refresh on the results page was removed.

## Actual browser results

A temporary headless Chrome session exercised the page's actual JavaScript.
No browser automation library was installed in the project. The employer's
local workbook was selected through the real file input and uploaded by the
form's submit handler.

| Check | Result |
|---|---|
| Upload response | HTTP 202 JSON, no redirect |
| Import ID | 4 |
| Queue result | `completed` |
| `processed_rows` / `total_rows` | 100,000 / 100,000 |
| Rows independently counted in MySQL for this import | 100,000 |
| Processing time | 43.927 seconds |
| `imports.error` | `NULL` |
| Upload page document requests | 1, the initial page load only |
| Maximum concurrent polling requests | 1 |
| Page after completion | Same upload URL, with completion message and results link |
| Uncaught JavaScript exceptions | 0 |

Repeated submit events while uploading produced one upload request. The browser
observed 17 JSON responses for the successful import, including the upload
response and status polling. Progress and the history row updated dynamically.
After completion, waiting longer than the polling delay produced no additional
status requests and the upload controls were enabled again.

The completed import remains available for review. Each repeat upload is a
separate import; records from earlier imports remain in the database.

## Error handling

- A text file produced HTTP 422; validation errors appeared on the page and the
  submit button was enabled again.
- A simulated browser network outage during upload showed a network error.
- A network outage during status polling paused updates. The status retry button
  resumed polling without uploading the workbook again. Repeated retry clicks
  did not create overlapping status requests.
- A small synthetic XLSX with invalid headers exercised the real queue's failed
  state. The error appeared in the UI and polling stopped. No page reload occurred.
  This deliberately failed test import and its failed job were removed afterward;
  the successful workbook import was preserved.

The existing Feature tests were updated for JSON upload responses, queued,
processing, completed and failed status responses, HTTP 422 validation and a
missing import's HTTP 404. All 8 tests passed with 58 assertions. Laravel Pint,
JavaScript syntax validation and `git diff --check` passed as well.
