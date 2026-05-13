<?php

namespace Myneid\LaravelDbTui\Database;

class PdoConnection
{
    private \PDO $pdo;
    private string $driver;

    public function __construct(\PDO $pdo, string $driver)
    {
        $this->pdo = $pdo;
        $this->driver = $driver;
    }

    /**
     * Build from Laravel's configured database connection.
     */
    public static function fromLaravel(?string $connection = null): self
    {
        $db = app('db')->connection($connection);
        return new self($db->getPdo(), $db->getDriverName());
    }

    /**
     * Build from a connection URL:
     *   mysql://user:pass@host:3306/dbname
     *   postgres://user:pass@host/dbname
     *   sqlite:/absolute/path/to/db.sqlite
     *   sqlite::memory:
     */
    public static function fromUrl(string $url): self
    {
        // sqlite is special — parse_url mangles paths
        if (str_starts_with($url, 'sqlite:')) {
            $path = substr($url, 7);
            $pdo = new \PDO("sqlite:{$path}", null, null, [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            ]);
            return new self($pdo, 'sqlite');
        }

        $parsed = parse_url($url);
        $scheme = $parsed['scheme'] ?? 'mysql';
        $host   = $parsed['host'] ?? 'localhost';
        $port   = $parsed['port'] ?? null;
        $user   = isset($parsed['user']) ? urldecode($parsed['user']) : null;
        $pass   = isset($parsed['pass']) ? urldecode($parsed['pass']) : null;
        $dbname = ltrim($parsed['path'] ?? '', '/');

        switch ($scheme) {
            case 'mysql':
            case 'mariadb':
                $dsn = "mysql:host={$host}" . ($port ? ";port={$port}" : '') . ($dbname ? ";dbname={$dbname}" : '') . ';charset=utf8mb4';
                $driver = 'mysql';
                break;

            case 'postgres':
            case 'postgresql':
            case 'pgsql':
                $dsn = "pgsql:host={$host}" . ($port ? ";port={$port}" : '') . ($dbname ? ";dbname={$dbname}" : '');
                $driver = 'pgsql';
                break;

            default:
                // Pass through raw DSN strings (e.g. "mysql:host=...")
                $dsn    = $url;
                $driver = explode(':', $url)[0];
        }

        $pdo = new \PDO($dsn, $user, $pass, [
            \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
        ]);

        return new self($pdo, $driver);
    }

    public function getDriver(): string
    {
        return $this->driver;
    }

    /** @return string[] */
    public function getTables(): array
    {
        $sql = match ($this->driver) {
            'mysql', 'mariadb' =>
                'SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE() ORDER BY table_name',
            'pgsql' =>
                "SELECT tablename FROM pg_tables WHERE schemaname = 'public' ORDER BY tablename",
            'sqlite' =>
                "SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name",
            default => throw new \RuntimeException("Unsupported driver: {$this->driver}"),
        };

        return $this->pdo->query($sql)->fetchAll(\PDO::FETCH_COLUMN);
    }

    /** @return string[] */
    public function getColumns(string $table): array
    {
        $columns = [];

        switch ($this->driver) {
            case 'mysql':
            case 'mariadb':
                $stmt = $this->pdo->query('DESCRIBE ' . $this->qi($table));
                foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
                    $columns[] = $row['Field'];
                }
                break;

            case 'pgsql':
                $stmt = $this->pdo->prepare(
                    "SELECT column_name FROM information_schema.columns
                     WHERE table_name = ? AND table_schema = 'public'
                     ORDER BY ordinal_position"
                );
                $stmt->execute([$table]);
                $columns = $stmt->fetchAll(\PDO::FETCH_COLUMN);
                break;

            case 'sqlite':
                $stmt = $this->pdo->query('PRAGMA table_info(' . $this->qi($table) . ')');
                foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
                    $columns[] = $row['name'];
                }
                break;

            default:
                throw new \RuntimeException("Unsupported driver: {$this->driver}");
        }

        return $columns;
    }

    public function getRowCount(string $table): int
    {
        return (int) $this->pdo
            ->query('SELECT COUNT(*) FROM ' . $this->qi($table))
            ->fetchColumn();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getRows(
        string  $table,
        int     $offset,
        int     $limit,
        ?string $sortCol,
        string  $sortDir = 'ASC'
    ): array {
        $sql = 'SELECT * FROM ' . $this->qi($table);

        if ($sortCol !== null) {
            $dir  = $sortDir === 'DESC' ? 'DESC' : 'ASC';
            $sql .= ' ORDER BY ' . $this->qi($sortCol) . ' ' . $dir;
        }

        $sql .= " LIMIT {$limit} OFFSET {$offset}";

        return $this->pdo->query($sql)->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Execute arbitrary SQL and return columns + rows.
     *
     * @return array{columns: string[], rows: array<int, array<string, mixed>>}
     */
    public function executeRaw(string $sql): array
    {
        $stmt = $this->pdo->query($sql);

        if ($stmt->columnCount() === 0) {
            return [
                'columns' => ['affected_rows'],
                'rows'    => [['affected_rows' => $stmt->rowCount()]],
            ];
        }

        $rows    = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        $columns = $rows ? array_keys($rows[0]) : [];

        return ['columns' => $columns, 'rows' => $rows];
    }

    /**
     * Return the first primary-key column name for a table, or null if none found.
     */
    public function getPrimaryKey(string $table): ?string
    {
        try {
            switch ($this->driver) {
                case 'sqlite':
                    $stmt = $this->pdo->query('PRAGMA table_info(' . $this->qi($table) . ')');
                    foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
                        if ((int) $row['pk'] === 1) {
                            return $row['name'];
                        }
                    }
                    return null;

                case 'mysql':
                case 'mariadb':
                    $stmt = $this->pdo->query(
                        'SHOW KEYS FROM ' . $this->qi($table) . " WHERE Key_name = 'PRIMARY'"
                    );
                    $row = $stmt->fetch(\PDO::FETCH_ASSOC);
                    return $row ? $row['Column_name'] : null;

                case 'pgsql':
                    $stmt = $this->pdo->prepare(
                        "SELECT a.attname
                         FROM pg_index i
                         JOIN pg_attribute a ON a.attrelid = i.indrelid
                             AND a.attnum = ANY(i.indkey)
                         WHERE i.indrelid = ?::regclass
                           AND i.indisprimary
                         LIMIT 1"
                    );
                    $stmt->execute([$table]);
                    $row = $stmt->fetch(\PDO::FETCH_ASSOC);
                    return $row ? $row['attname'] : null;

                default:
                    return null;
            }
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * UPDATE a single row.
     *
     * Uses the primary key for the WHERE clause when available; falls back to
     * matching all original column values (safe but slow on large tables).
     *
     * @param array<string, mixed> $originalRow  The row as fetched from the DB
     * @param array<string, mixed> $changedValues Only the columns that changed
     * @return int Affected row count
     */
    public function updateRow(string $table, array $originalRow, array $changedValues): int
    {
        if (empty($changedValues)) {
            return 0;
        }

        $pk = $this->getPrimaryKey($table);

        // SET clause
        $setParts = [];
        $params   = [];
        foreach ($changedValues as $col => $val) {
            $setParts[] = $this->qi($col) . ' = ?';
            $params[]   = $val === '' ? null : $val;
        }

        // WHERE clause
        if ($pk !== null && array_key_exists($pk, $originalRow)) {
            $where    = $this->qi($pk) . ' = ?';
            $params[] = $originalRow[$pk];
        } else {
            // Fallback: match every original column
            $whereParts = [];
            foreach ($originalRow as $col => $val) {
                if ($val === null) {
                    $whereParts[] = $this->qi($col) . ' IS NULL';
                } else {
                    $whereParts[] = $this->qi($col) . ' = ?';
                    $params[]     = $val;
                }
            }
            $where = implode(' AND ', $whereParts);
        }

        $sql  = 'UPDATE ' . $this->qi($table) . ' SET ' . implode(', ', $setParts) . ' WHERE ' . $where;
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->rowCount();
    }

    /** Quote an identifier for the current driver. */
    private function qi(string $name): string
    {
        return match ($this->driver) {
            'mysql', 'mariadb' => '`' . str_replace('`', '``', $name) . '`',
            default            => '"' . str_replace('"', '""', $name) . '"',
        };
    }
}
