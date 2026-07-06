# laravel-db-tui

I built this because I kept getting frustrated bouncing between TablePlus, my terminal, and VSCode just to check on data or run a quick query. SSHing into a server to use the MySQL CLI is painful, and switching apps constantly breaks the flow. I wanted something that lived right in the console — where I already spend most of my time — that could handle the things I actually reach for a GUI to do: browse tables, sort, inspect a row, edit a value, run ad-hoc SQL. This is that tool.

An interactive terminal UI for browsing and editing your Laravel application's database — or any remote MySQL, PostgreSQL, or SQLite database — without leaving the command line.

```
┌─[Tables]──────────────┬─[users] (rows 1–200 of 4312)─────────────────────────┐
│ ▶ users               │  id  │ name          │ email               │ created_at│
│   posts               │──────┼───────────────┼─────────────────────┼───────────│
│   comments            │  1   │ Alice Nguyen  │ alice@example.com   │ 2024-01-01│
│   password_resets     │  2   │ Bob Okonkwo   │ bob@example.com     │ 2024-01-02│
│   sessions            │  3   │ Carol Perez   │ carol@example.com   │ 2024-01-03│
│   jobs                │  4   │ Dave Müller   │ dave@example.com    │ 2024-01-04│
└───────────────────────┴───────────────────────────────────────────────────────┘
 ↑↓/jk: row | Tab/←: tables | Enter: detail | 1-9: sort | PgUp/n PgDn/p: page | s: SQL | q: quit
```

## Features

- **Browse any table** — scrollable table list on the left, paginated row view on the right
- **Sort columns** — press `1`–`9` to sort by that column, again to toggle ASC/DESC
- **Inspect rows** — press `Enter` on a row to see every field in a word-wrapped key/value layout
- **Edit and save rows** — edit any field in the detail view and write it back to the database
- **SQL popup runner** — open a query window from anywhere, execute SQL, and inspect results
- **Context-aware autocomplete** — table names after `FROM/JOIN`, columns in `SELECT/WHERE`, `alias.column` completion with `Tab`
- **Saved connections** — store named connection URLs in `~/.laravel-db-tui.json`, outside any repo
- **Remote connections** — connect to any MySQL, PostgreSQL, or SQLite database via URL
- **Faster remote browsing** — table metadata/page results are cached in-memory during a session
- **Mouse support** — click to select a table or row, scroll wheel to navigate
- **Uses Laravel's existing config** — zero setup for the default database connection

## Requirements

- PHP 8.1+
- Laravel 10, 11, 12, or 13
- A terminal that supports ANSI escape codes (macOS Terminal, iTerm2, Windows Terminal, most Linux terminals)

## Installation

```bash
composer require myneid/laravel-db-tui
```

Laravel's package auto-discovery will register the `db:tui` command automatically. No service provider registration needed.

> **First time publishing this package?** Replace `myneid` with your actual Packagist vendor name in `composer.json` and in all `namespace` declarations across the `src/` files.

## Quick start

```bash
# Open the default database connection configured in .env
php artisan db:tui

# Done. Use Tab to switch panels, ↑↓ to navigate, q to quit.
```

## Usage

```
php artisan db:tui [options]
```

| Option | Description |
|---|---|
| `--connection=<name>` | Use a named connection from `config/database.php` (e.g. `mysql`, `pgsql`, `sqlite`) |
| `--url=<url>` | Connect to a remote database via URL — bypasses Laravel's config entirely |
| `--save-as=<name>` | Save the `--url` connection under a name for future use |
| `--saved=<name>` | Connect using a previously saved connection |
| `--list-saved` | Print all saved connection names and exit |
| `--forget=<name>` | Remove a saved connection and exit |

### `--url` format

```bash
# MySQL / MariaDB
php artisan db:tui --url="mysql://user:password@host:3306/database"

# PostgreSQL
php artisan db:tui --url="postgres://user:password@host:5432/database"

# SQLite (absolute path)
php artisan db:tui --url="sqlite:/absolute/path/to/database.sqlite"

# SQLite (in-memory, useful for testing)
php artisan db:tui --url="sqlite::memory:"
```

Passwords with special characters should be URL-encoded (e.g. `p@ss` → `p%40ss`).

### Saved connections

Connection URLs (including passwords) are stored in `~/.laravel-db-tui.json` in your home directory. This file is never inside a project, so it cannot be accidentally committed.

```bash
# Save a production URL — only needs to be done once
php artisan db:tui --url="mysql://user:s3cr3t@prod-host/mydb" --save-as=production

# Connect to it by name from any project
php artisan db:tui --saved=production

# See what's saved (passwords are masked in the output)
php artisan db:tui --list-saved

# Remove a saved connection
php artisan db:tui --forget=production
```

## Key bindings

### Tables panel (left)

| Key | Action |
|---|---|
| `↑` / `k` | Move up |
| `↓` / `j` | Move down |
| `Enter`, `Tab`, `→` | Load the selected table and switch focus to the data panel |
| `s`, `:`, `Ctrl+E` | Open SQL popup |
| `q` / `Ctrl+C` | Quit |

### Data panel (right)

| Key | Action |
|---|---|
| `↑` / `k` | Previous row |
| `↓` / `j` | Next row |
| `Enter` | Open row detail view |
| `Tab`, `←`, `Shift+Tab` | Switch focus to the table list |
| `1`–`9` | Sort by column N (press again to toggle ASC ↔ DESC) |
| `PgUp` / `p` | Previous page |
| `PgDn` / `n` | Next page |
| `Home` | First page, first row |
| `End` | Last page, last row |
| `s`, `:`, `Ctrl+E` | Open SQL popup |
| `q` / `Ctrl+C` | Quit |

### Row detail view

Fields are word-wrapped. Edited-but-unsaved fields are shown in yellow with a `●` marker. The title bar shows **[UNSAVED CHANGES]** when there is anything pending.

| Key | Action |
|---|---|
| `↑` / `k` | Move to previous field |
| `↓` / `j` | Move to next field |
| `e` or `Enter` | Start editing the focused field |
| `Backspace` | Delete last character (while editing) |
| `Enter` | Confirm the edit — marks field as dirty |
| `Esc` | Cancel the current edit without saving |
| `s` | Save all dirty fields to the database (`UPDATE`) |
| `Esc` *(not editing)* | Back to data panel |
| `q` | Quit |

> **Storing null:** type the literal word `NULL` (uppercase) in the edit buffer and confirm — the field will be written as a database `NULL`.

Saving uses the table's primary key as the `WHERE` clause when one is detected, and falls back to matching all original column values if no primary key is found.

### SQL editor

```
┌─ SQL Popup  Enter: run  |  Tab: complete  |  Esc: close ───────────────────────┐
│ SELECT * FROM users WHERE email LIKE '%@example.com'█                          │
│ Suggestions:                                                                    │
│ ▶ users                                                                         │
│   user_profiles                                                                 │
├─ Results (3 rows) ─────────────────────────────────────────────────────────────┤
│  id  │ name         │ email                │ created_at                         │
│▶ 1   │ Alice Nguyen │ alice@example.com    │ 2024-01-01 00:00:00                │
│  2   │ Bob Okonkwo  │ bob@example.com      │ 2024-01-02 00:00:00                │
└────────────────────────────────────────────────────────────────────────────────┘
```

| Key | Action |
|---|---|
| Type normally | Build your query |
| `Backspace` | Delete last character |
| `Tab` | Accept the selected suggestion |
| `↑` / `↓` | Move through suggestions (or results when no suggestions are shown) |
| `Enter` | Execute the query |
| `Esc` | Close popup and return to previous mode |

Autocomplete is context-aware:

- after `FROM`, `JOIN`, `UPDATE`, `INTO`: suggests table names
- after `SELECT`, `WHERE`, `ON`, `ORDER BY`, `GROUP BY`: suggests columns from tables in your query
- for `alias.` or `table.`: suggests matching columns for that alias/table

Non-`SELECT` statements (INSERT, UPDATE, DELETE, CREATE, etc.) are supported — the results panel shows the number of affected rows.

### SQL result row detail

Click any result row to open the same full key/value edit view used for table rows (see [Row detail (edit mode)](#row-detail-edit-mode) below).

| Key | Action |
|---|---|
| `↑` / `k` | Previous field |
| `↓` / `j` | Next field |
| `e` / `Enter` | Edit the focused field |
| `s` | Save staged changes |
| `y` | Copy the focused field |
| `Esc` | Back to SQL results |
| `q` | Quit |

> Editing is only enabled when the query is a plain `SELECT` from a single table (no `JOIN`, and every selected column maps to a real column on that table). Anything else — joins, computed/aliased columns, non-`SELECT` statements — opens the same view read-only, marked `[READ-ONLY]` in the title.

### Mouse

| Action | Effect |
|---|---|
| Scroll up/down | Navigate rows or tables in the focused panel |
| Click a table name | Select that table and load its data |
| Click a data row | Select that row |
| Click a selected row | Open row detail view |
| Click a SQL result row | Open that row's detail view |

## UI panels

### Browse mode

```
┌─ Tables ───────────────┬─ Data / Row detail ────────────────────────────────────┐
│                        │                                                         │
│  table list            │  paginated rows   or   single-row key/value view        │
│                        │                                                         │
└────────────────────────┴─────────────────────────────────────────────────────────┘
 help bar
```

The **active panel** is indicated by a double-line border (`╔═╗`) and brackets around the title. Inactive panels use a rounded border (`╭─╮`).

### Row detail (edit mode)

```
╔═ Row 2 of 4312  [UNSAVED CHANGES] ══════════════════════════════════════════════╗
║  Column      │ Value                                                             ║
║──────────────┼───────────────────────────────────────────────────────────────── ║
║  id          │ 2                                                                 ║
║● name        │ Bob Okonkwo                           ← highlighted (focused)    ║
║  email       │ bob-new@example.com                   ← yellow (dirty, unsaved)  ║
║  created_at  │ 2024-01-02 00:00:00                                               ║
╚══════════════════════════════════════════════════════════════════════════════════╝
 e/Enter: edit field  s: save  Esc: back  q: quit  (type NULL to store null)
```

## Architecture

The package follows a simple four-layer design:

```
DbTuiCommand          — entry point, terminal setup, event loop
    │
    ├── ConnectionStore  — reads/writes ~/.laravel-db-tui.json
    │
    ├── PdoConnection — database abstraction (SQLite / MySQL / PostgreSQL)
    │       getTables, getColumns, getRows, getRowCount
    │       getPrimaryKey, updateRow
    │       executeRaw
    │
    ├── App           — all state + input event handling
    │       Mode enum: Tables | Data | Row | Sql | SqlRow
    │
    └── Renderer      — converts App state → php-tui widget tree (read-only)
```

**State machine** (`App`): The `Mode` enum drives which key bindings are active and which panel is rendered. State transitions are:

```
Tables ⇄ Data → Row (edit + save)
              ↘
              Sql → SqlRow (edit + save when the query is a single plain table; read-only otherwise)

Sql popup is reachable from Tables/Data/Row with `s`, `:`, or `Ctrl+E`; Esc returns to the previous mode.
```

**Editing** (`App`): Row detail tracks `editFieldIndex`, `isEditing`, `editBuffer`, and `dirtyValues`. Confirming an edit stages the change in `dirtyValues`; pressing `s` flushes all staged changes in a single `UPDATE` statement and refreshes the page. `Row` and `SqlRow` share this same mechanism — `SqlRow` resolves its target table via `sqlResultTable`, detected from the executed SQL (`null` when the query isn't a single plain table, which disables saving).

**Rendering** (`Renderer`): Stateless — called every frame (~60 fps), reads `App` and builds a `GridWidget` tree. php-tui diffs the widget tree against the previous frame before writing to the terminal.

**Database** (`PdoConnection`): Raw PDO only — no Eloquent, no query builder. Identifiers are quoted per-driver to prevent injection from schema-derived names. Raw SQL mode executes user input directly (intentional for a developer tool). `updateRow()` uses the primary key as the `WHERE` predicate when available.

**Performance** (`App`): While the TUI is open, per-table metadata (`columns`, `COUNT(*)`) and fetched pages are cached in memory. Moving through the table list no longer eagerly loads every highlighted table; load happens when you open a table with `Enter`/`Tab`/`→` (or click into the data panel).

## Customising

### Page size

The default page size is 200 rows. To change it, set `$app->limit` before calling `$app->init()` in `DbTuiCommand::handle()`:

```php
$app = new App($db);
$app->limit = 500;
$app->init();
```

### Adding a connection type

`PdoConnection` handles the three standard drivers. To add another (e.g. SQL Server via `sqlsrv`), extend `PdoConnection` and override `getTables()`, `getColumns()`, `getPrimaryKey()`, and `qi()`.

## License

[MIT](LICENSE) — do whatever you want with it.
