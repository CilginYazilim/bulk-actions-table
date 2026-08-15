<div align="center">

<img src="assets/images/logo.png" alt="Çılgın Yazılım" width="90">

# Bulk Actions Table

**Multi-select and bulk actions on a server-side DataTable.**
Selected records · ALL matching the filter · Confirmation with the real count · Confirmed-count guard

[![PHP](https://img.shields.io/badge/PHP-8.0%2B-777BB4?style=flat-square&logo=php&logoColor=white)](https://www.php.net/)
[![MySQL](https://img.shields.io/badge/MySQL-5.7%2B-4479A1?style=flat-square&logo=mysql&logoColor=white)](https://www.mysql.com/)
[![License](https://img.shields.io/badge/License-MIT-brightgreen?style=flat-square)](LICENSE)

[cilginyazilim.com](https://cilginyazilim.com) &nbsp;·&nbsp; [Türkçe](README.md) | **English**

</div>

---

<div align="center">

<img src="assets/images/screenshot-bulk-bar.png" alt="Bulk action bar with the select-all-matching link" width="880">

<sub>All 10 rows on this page are checked. The bar appeared, and so did the link<br><b>“Select all 60 records matching the filter”</b> — the gateway to the second meaning of “selected”.</sub>

</div>

---

## What does this project do?

You can check rows in a newsletter subscriber list and perform **bulk status changes** and **bulk deletions**. But what it actually teaches is not a UI trick:

> In a server-side table, the word **“selected” has two different meanings** — and code that conflates them will delete more than the user approved.

This project establishes that distinction, routes both meanings through a single correct path, and measures and closes every gap in between.

---

## The two meanings of “selected”

| Mode | When it happens | What goes to the server |
|------|-----------------|-------------------------|
| **Specific records** (`scope=selected`) | The user checks a few rows one by one | `ids[]` — an explicit id list |
| **ALL matching the filter** (`scope=filtered`) | The user checks everyone on this page, then clicks “Select all N records matching the filter” | The search + status filter — **no id list at all** |

### Why is the second mode needed? — the Gmail analogy

In a server-side DataTable the user **never sees all rows at once**; only the current page. In a 50,000-row list, downloading 50,000 ids to the browser just to send them back is both slow and pointless.

When you search in Gmail and tick the header checkbox, you get: *“All 50 conversations on this page are selected — **select all 4,312 conversations that match this search**.”* Clicking that second link does not send Gmail 4,312 identifiers; it tells the server **“re-apply the same search condition and act on everyone it matches.”**

This project builds exactly that pattern:

```
resolve_bulk_scope()                             (system/ajax.php)
   scope=selected  →  WHERE id IN (:id0, :id1, :id2, …)
   scope=filtered  →  WHERE (name LIKE :s_name OR email LIKE :s_email
                             OR segment LIKE :s_segment) AND status = :status
```

### The crucial point: the condition is built in ONE place

The `scope=filtered` condition comes from **exactly the same function** that produces the on-screen list: [`subscriber_filter()`](system/function.php).

That condition used to be duplicated in three places (listing, counter, bulk action). When one copy is updated and another is forgotten, the resulting bug is silent and destructive: the user sees 12 people on screen, clicks “select all”, and the bulk action deletes 15. Removing the duplication is therefore not housekeeping here — it is a **security control**.

---

## Two separate brakes against accidental deletion

<div align="center">

<img src="assets/images/screenshot-bulk-confirm.png" alt="Bulk delete confirmation: 60 records will be permanently DELETED" width="760">

<sub>Not <b>“Are you sure?”</b> but <b>“60 records will be permanently DELETED.”</b></sub>

</div>

### Brake 1 — Confirmation with the real count (`bulk_preview`)

When a bulk action is triggered, the client first calls `bulk_preview`. The number comes from **`resolve_bulk_scope()` itself** — the very function that will perform the action. Had the preview used a separate calculation path, the number it displayed would have been merely decorative.

The count is always **read from the database**. Previously, in the selected scope, the server returned the number of ids the client had sent: submitting 10 non-existent ids made the dialog say “10 records will be DELETED” while 0 were actually deleted. Since the confirmation dialog exists solely to show the truth, that number cannot come from the client.

### Brake 2 — The confirmed-count guard (`expected_count`)

The list can change between the preview and the confirmation. Measured scenario: the user approved **8 records**, another tab inserted one in between, and the delete removed **9**.

The client now sends the number it **displayed** in the dialog back with the action request (`expected_count`). The server compares it against its own count:

```
expected_count missing              → 422, nothing happens
expected_count ≠ real count         → 409, nothing happens, current count returned
count shifts during the write       → transaction rolled back, 409
```

The UI treats 409 not as an error but as a brake: it refreshes the list and asks for confirmation again **with the current number**.

> **Approving “8 records will be deleted” does not authorise deleting 9.** That sentence summarises the entire project.

---

## Issues measured and closed

Every item below was **measured** over HTTP against the running install, fixed, and **measured again**.

| # | Issue | Before | After |
|---|-------|--------|-------|
| 1 | Bulk status change with `scope=filtered` crashed **every time** a filter was active (named and positional placeholders mixed) | `HTTP 500` · `SQLSTATE[HY093]` | `HTTP 200` · correct rows updated |
| 2 | The same faulty binding **silently worked** for deletion — broken code exploded on one endpoint and deleted data on another | `200` · 57 records deleted | Both now go through the single `execute_bulk()` path |
| 3 | In the selected scope the preview count came **from the client** | 10 fake ids → “10 records will be deleted”, actually 0 | Count from the database: `count 0` |
| 4 | Stale preview: more was deleted than approved | 8 approved → **9 deleted** | `HTTP 409`, nothing deleted |
| 5 | CSRF rejection returned 419; Apache silently rewrites it to 500 | `HTTP 500` | `HTTP 403` |
| 6 | `action=list` required **no** CSRF token — an unauthenticated request returned all 60 subscribers (names, e-mails) | `HTTP 200` · 60 records | `HTTP 403` |
| 7 | An invalid status filter was **silently ignored**, widening the scope to the whole table | `status_filter=bogus` → `count 60` | `HTTP 422` |
| 8 | `system/` was reachable from the web; `config.php` opened a database connection on every request | `/system/config.php` → `200` | `403` (`.htaccess` + `CY_APP`) |
| 9 | No indexes on the sortable columns | `ORDER BY name`, 123,000 rows → **355 ms** (`Using filesort`) | **1 ms** (`Using index`) |
| 10 | The same `COUNT(*)` ran twice when no filter was active | +175 ms on every page change | a single query |

### Things that were probed and found **sound**

These were measured too; **no gap was found** and the code was left as it was:

- **SQL injection — sort column:** the client sends an array index, not a column name; an unrecognised index falls back to `id`. SQL fragments posted into `order[0][column]` left the query unaffected.
- **XSS:** `<script>alert(1)</script>` and `"><img src=x onerror=…>` payloads were stored as records; the list returned them escaped as `&lt;script&gt;`. `table.js` writes text with `.text()`, never `.html()`.
- **LIKE wildcards:** searching for `%` returns 0 results (`escape_like()` works) — otherwise a one-character search would pull the whole table into the “all matching” scope.
- **`BULK_MAX_IDS = 500`:** posting 501 ids → `HTTP 422`. The limit is genuinely enforced.
- **CSRF token bound to the session:** a request carrying another session’s token got `403`.

### Behaviour deliberately **left unchanged**

**With an empty filter, `scope=filtered` targets the whole table.** Measured: `bulk_preview` → `count: 60` (60 of 60). This is exactly Gmail’s “select all conversations” behaviour and it was **not removed** — clearing the filter and selecting everything is a legitimate request; forbidding it would force users into rounds of 500 records.

It was judged sufficiently protected because the operation now passes **three separate gates**: the preview states the real number (“60 records will be permanently DELETED”), the approved number is verified server-side, and entering this mode requires a distinct deliberate click. The number itself is the strongest warning: when it equals the whole table, the user reads it.

### Transactions: why they are here, and why they would not be needed alone

`bulk_status` and `bulk_delete` each run a single SQL statement — **single-statement DML is already atomic**, so a transaction on its own would be redundant. The real justification is the post-write check “did I touch more than was approved?”: only inside a transaction do the count, the write and the rollback decision become **one unit**. Without it, we could detect the discrepancy but never bring the deleted rows back. ([system/ajax.php](system/ajax.php) `execute_bulk()`)

---

## Security layers and the **reason** for each

| Layer | Where | Why it is there |
|-------|-------|-----------------|
| **CSRF token** (writes **and** reads) | `require_csrf()` | Stops a tab open on another site from triggering a bulk delete with the user’s session. Mandatory for reads too: `list` returns a customer list, and leaking it is harm by itself. |
| **Rejecting with 403** | `require_csrf()` | 419 is not an official code; on this Apache install it silently became 500, so the client saw “server crashed” instead of “your session expired”. |
| **Prepared statements, named everywhere** | all queries | User data never enters SQL text. A single rule (“named everywhere”) makes mixing the two styles structurally impossible. |
| **Whitelisted sort column** | `handle_list()` | A column name cannot be bound as a parameter; it enters the SQL as text. The client sends an index, not a name. |
| **`escape_like()`** | `subscriber_filter()` | To a user, `%` and `_` are characters, not wildcards. Unescaped, a one-character search covers the entire table. |
| **Whitelist validation** | `validate_status()`, scope and status filter | An unrecognised value is **rejected**, not ignored. A failure that silently widens the scope is far more dangerous than a loud one. |
| **`BULK_MAX_IDS`** | `config.php` | Prevents straining the server with thousands of ids; anything larger is `scope=filtered`’s job. |
| **Confirmed-count guard** | `execute_bulk()` | The user’s approval is for a specific NUMBER. If the number changed, the approval is void. |
| **Output escaping** | `e()` | A subscriber whose name is `<script>` could otherwise run code for everyone who opens the list. |
| **`system/.htaccess` + `CY_APP`** | folder + file headers | A whitelist: only `ajax.php` is open, so a file added tomorrow is closed by default. `CY_APP` is the second layer for servers that never read `.htaccess` (nginx). |
| **Root `.htaccess`** | project root | `.sql`/`.md` cannot be downloaded; `nosniff`, `X-Frame-Options` and `Referrer-Policy` are added. In an invisible frame, a user can be tricked into clicking “Delete selected”. |

---

## Installation

```bash
cd C:/xampp/htdocs
git clone https://github.com/CilginYazilim/bulk-actions-table.git

mysql -u root -p < bulk-actions-table/cy_bulk.sql
```

In the browser: **http://localhost/bulk-actions-table/**

> **Before going live**, set `APP_DEBUG` to `false` in `system/config.php` — while it is on, database error messages are sent to the client.

**Requirements:** PHP 8.0+ (PDO MySQL), MySQL 5.7+ / MariaDB 10.3+, Apache (`mod_headers` recommended). No external dependencies; jQuery, Bootstrap and DataTables ship with the repository.

---

## File layout

```
bulk-actions-table/
├── index.php                  ← UI: table, bulk action bar, three modals
├── cy_bulk.sql                ← Database setup (cy_bulk, 60 sample subscribers)
├── .htaccess                  ← Directory listing off, .sql/.md denied, security headers
├── system/
│   ├── .htaccess              ← Whitelist: only ajax.php is exposed
│   ├── config.php             ← Settings, status definitions, PDO connection
│   ├── function.php           ← Output/CSRF/validation + subscriber_filter() (single source of truth)
│   └── ajax.php               ← 8 endpoints + resolve_bulk_scope() + execute_bulk()
└── assets/
    ├── css/cilginyazilim.css  ← Brand design system (shared)
    ├── css/style.css          ← Page-specific styles
    └── js/table.js            ← Selection state: selectedIds / selectAllMatching
```

### What does each function do?

| Function | File | Role |
|----------|------|------|
| `subscriber_filter()` | `function.php` | **The single source of the search + status condition.** Listing, counter and bulk actions all draw from it. |
| `count_subscribers()` | `function.php` | Number of records matching the filter — takes its condition from the function above. |
| `require_csrf()` | `function.php` | Compares the token with `hash_equals()`, otherwise `403`. |
| `escape_like()` | `function.php` | Strips `%` and `_` of their wildcard meaning. |
| `e()` | `function.php` | HTML output escaping. |
| `handle_list()` | `ajax.php` | DataTables server-side protocol: paging, sorting (whitelisted), filtering. |
| `resolve_bulk_scope()` | `ajax.php` | **Resolves which records the request targets.** Returns `[WHERE, named params, real count]`. |
| `execute_bulk()` | `ajax.php` | **The single exit gate for bulk actions.** Count guard + transaction + one binding path. |
| `handle_bulk_preview()` | `ajax.php` | Produces the real number shown in the confirmation dialog. |
| `restoreCheckboxState()` | `table.js` | Rebuilds checkbox marks from internal state after a page change. |
| `scopeParams()` | `table.js` | The client-side mirror of `resolve_bulk_scope()`. |

### Where does the selection live?

Entirely on the client: a `Set<number>` (`selectedIds`) and a flag (`selectAllMatching`) in `table.js`. The server **always** returns unchecked checkboxes; the client decides which rows appear checked (`restoreCheckboxState()`).

When the search or status filter changes, the selection is **reset automatically**: a “all matching the filter” selection is tied to one specific filter, and once the filter changes, the meaning of that selection becomes undefined.

**With two tabs open:** each tab has its own `selectedIds`; they neither see nor need to see each other, because a selection is a user intent, not shared state. The real cross-tab risk is not the selection but the **count** drifting — and that is closed by the `expected_count` guard (measured: `HTTP 409`, data unchanged).

---

## API endpoints

All go to `system/ajax.php` via **POST** and return JSON. **All of them**, including `action=list`, require `csrf_token`.

| `action` | Parameters | Returns |
|----------|------------|---------|
| `list` | DataTables fields + `status_filter` | `draw`, `recordsTotal`, `recordsFiltered`, `data[]` |
| `add` | `name`, `email`, `segment`, `status` | `id` |
| `edit` | `subscriber_id` + the above | `id` |
| `fetch` | `id` | The record’s fields |
| `delete` | `id` | `id` |
| `bulk_preview` | `scope` + scope fields | `count` — **real**, from the database |
| `bulk_status` | `scope` + scope fields + `new_status` + `expected_count` | `updated` |
| `bulk_delete` | `scope` + scope fields + `expected_count` | `deleted` |

**Scope fields:** `scope=selected` → `ids[]` · `scope=filtered` → `search`, `status_filter`

### HTTP status codes

| Code | Meaning |
|------|---------|
| `200` | Success |
| `400` | Malformed request (e.g. `ids` is not an array) |
| `403` | CSRF validation failed · direct file access |
| `404` | Record not found |
| `405` | Non-POST request |
| `409` | **The list changed after confirmation — nothing was done** |
| `422` | Validation error · invalid scope/status · `BULK_MAX_IDS` exceeded · `expected_count` missing |
| `500` | Unexpected server error |

> `419` is deliberately **not used**: it is not an official code, and on this Apache install it silently becomes `500`, misleading the client.

---

## Database schema

`cy_bulk` · a single table: `subscribers` (60 sample records — 47 active, 9 passive, 4 blocked)

| Column | Type | Note |
|--------|------|------|
| `id` | `INT UNSIGNED` | `PRIMARY KEY`, auto increment |
| `name` | `VARCHAR(150)` | `idx_subscribers_name` — for sorting |
| `email` | `VARCHAR(190)` | `UNIQUE` |
| `segment` | `VARCHAR(60)` | `idx_subscribers_segment`, defaults to `Genel` |
| `status` | `ENUM('aktif','pasif','engelli')` | `idx_subscribers_status` |
| `created_at` | `TIMESTAMP` | `idx_subscribers_created_at` — for sorting |

### Why indexes on those columns? (measured with 123,000 rows)

| Query | Before index | After index |
|-------|--------------|-------------|
| `ORDER BY name` | **355 ms** (`type: ALL`, 121,675 rows, `Using filesort`) | **1 ms** (`type: index`, 10 rows, `Using index`) |
| `ORDER BY created_at` | 134 ms | 1 ms |
| End to end: list sorted by name | 578 ms | **67 ms** |

**Why is search still ~300 ms?** Search uses `LIKE '%text%'`, and a pattern with a leading wildcard **cannot use any index**. The fix is not another index but a `FULLTEXT` index or a dedicated search engine — deliberately left out to keep the example simple.

---

## Customisation

**Adding a new status** — one place is enough, in `config.php`:

```php
define('SUBSCRIBER_STATUSES', [
    'aktif'   => ['label' => 'Aktif',   'css' => 'success'],
    'pasif'   => ['label' => 'Pasif',   'css' => 'secondary'],
    'engelli' => ['label' => 'Engelli', 'css' => 'danger'],
    'beklemede' => ['label' => 'Beklemede', 'css' => 'warning'],   // ← new
]);
```

The form dropdown, the filter box, the badges, the bulk action menu and validation **all draw from here**. Remember to widen the `ENUM` in `cy_bulk.sql` as well.

**Changing what search covers** — only the condition inside `subscriber_filter()`. Listing and bulk actions update together automatically; the two drifting apart is **structurally impossible**.

**Changing the selection limit** — `BULK_MAX_IDS` in `config.php`.

**Adding a new bulk action** (e.g. “bulk change segment”) — hand `execute_bulk()` an SQL template; the count guard, the transaction and parameter binding come for free:

```php
[$affected] = execute_bulk(
    $db,
    'UPDATE subscribers SET segment = :segment WHERE {where}',
    [':segment' => $segment]
);
```

**Adapting it to your own table** — replace `subscribers` with your table and `SUBSCRIBER_STATUSES` with your own field definitions. The bulk engine is bound to the **condition**, not to the table.

---

## Example use cases

- **Newsletter / e-mail marketing panels** — “deactivate everyone in this segment”, “bulk-block all hard-bouncing addresses”.
- **E-commerce administration** — “unpublish every out-of-stock product”, “apply a bulk discount to 400 products in this category”.
- **Comment / content moderation** — “hide all comments by this user”, “bulk-approve 250 pending comments”.
- **Membership and CRM systems** — “bulk-archive members who have not logged in for a year”.
- **Order / ticket management** — “mark today’s shipped orders as completed”.
- **Data cleanup** — “filter unverified records and bulk delete” — where the `expected_count` brake earns its keep the most.

The common thread: in each case the user can select **more than they can see**, and most of these actions **cannot be undone**.

---

## License

MIT — download, use, modify and ship it in commercial projects as you like. See [LICENSE](LICENSE) for details.

<div align="center">

**Çılgın Yazılım** · [cilginyazilim.com](https://cilginyazilim.com)
[github.com/CilginYazilim/bulk-actions-table](https://github.com/CilginYazilim/bulk-actions-table)

</div>
