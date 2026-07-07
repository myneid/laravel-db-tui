<?php

namespace Myneid\LaravelDbTui\Tui;

use Myneid\LaravelDbTui\Database\PdoConnection;

/**
 * Holds all application state and handles input events.
 * The renderer reads this state to build the UI each frame.
 */
class App
{
    // ── Mode ──────────────────────────────────────────────────────────────
    public Mode $mode = Mode::Tables;

    // ── Table list (left panel) ───────────────────────────────────────────
    /** @var string[] */
    public array $tables     = [];
    public int   $tableIndex = 0;  // selected row in the table list

    // ── Data table (right panel) ──────────────────────────────────────────
    /** @var string[] */
    public array $columns = [];
    /** @var array<int, array<string, mixed>> */
    public array $rows      = [];
    public int   $rowIndex  = 0;   // selected row within the current page
    public int   $totalRows = 0;
    public int   $dataPage  = 0;
    public int   $limit     = 200;

    public ?int  $sortColumn = null;
    public string $sortDir   = 'ASC';

    // ── Column widths / visibility ─────────────────────────────────────────
    private const MIN_COL_WIDTH        = 4;
    private const MAX_COL_WIDTH        = 40;  // ceiling for auto-computed default widths
    private const MAX_MANUAL_COL_WIDTH = 200; // ceiling for user-driven resize

    /** @var array<string, int> "table.column" => user-set width override */
    public array $columnWidths  = [];
    /** @var array<string, true> "table.column" => hidden */
    public array $hiddenColumns = [];
    /** @var array<string, int> "table.column" => auto-computed default width (refreshed on fetch) */
    private array $defaultColumnWidths = [];
    public int   $columnOffset   = 0;  // first visible column shown in the Data table (horizontal scroll)
    public int   $columnMgrIndex = 0;  // selected column row in the Columns popup

    // ── SQL editor ────────────────────────────────────────────────────────
    public string  $sqlInput    = '';
    /** @var string[] */
    public array   $sqlColumns  = [];
    /** @var array<int, array<string, mixed>> */
    public array   $sqlRows     = [];
    public ?string $sqlError    = null;
    public int     $sqlRowIndex = 0;  // selected row in the results table
    public ?string $sqlResultTable = null; // single source table for the last SELECT, if editable
    /** @var string[] */
    public array   $sqlSuggestions = [];
    public int     $sqlSuggestionIndex = 0;

    // ── Row editing ───────────────────────────────────────────────────────
    public int     $editFieldIndex = 0;    // focused field in the detail view
    public bool    $isEditing      = false; // actively typing a new value
    public string  $editBuffer     = '';
    public int     $editCursor     = 0;     // cursor offset inside editBuffer
    /** @var array<string, string|null> */
    public array   $dirtyValues    = [];    // unsaved edits: col => new value
    public ?string $saveMessage    = null;  // feedback after save attempt

    // ── General ───────────────────────────────────────────────────────────
    public ?string $statusMessage = null;
    public bool    $running       = true;
    private ?string $loadedTable  = null;
    private Mode $sqlReturnMode   = Mode::Tables;

    /** @var array<string, array{columns: string[], totalRows: int}> */
    private array $tableMetaCache = [];
    /** @var array<string, array<string, array<int, array<string, mixed>>>> */
    private array $rowPageCache   = [];
    /** @var array<string, string[]> */
    private array $columnCache = [];

    public function __construct(private readonly PdoConnection $db)
    {
    }

    // ── Init ──────────────────────────────────────────────────────────────

    public function init(): void
    {
        $this->tables = $this->db->getTables();
        if (!empty($this->tables)) {
            $this->loadTableData($this->tables[0]);
        }
    }

    // ── Accessors ─────────────────────────────────────────────────────────

    public function selectedTable(): string
    {
        return $this->tables[$this->tableIndex] ?? '';
    }

    public function hasLoadedSelectedTable(): bool
    {
        $table = $this->selectedTable();
        return $table !== '' && $table === $this->loadedTable;
    }

    /** @return array<string, mixed> */
    public function currentRow(): array
    {
        return $this->rows[$this->rowIndex] ?? [];
    }

    public function pageOffset(): int
    {
        return $this->dataPage * $this->limit;
    }

    // ── Column widths / visibility ──────────────────────────────────────────

    private function colKey(string $col): string
    {
        return $this->selectedTable() . '.' . $col;
    }

    public function isColumnHidden(string $col): bool
    {
        return !empty($this->hiddenColumns[$this->colKey($col)]);
    }

    /** @return string[] columns of the selected table that aren't hidden, in original order */
    public function visibleColumns(): array
    {
        return array_values(array_filter($this->columns, fn (string $c) => !$this->isColumnHidden($c)));
    }

    public function columnWidth(string $col): int
    {
        $key = $this->colKey($col);
        return $this->columnWidths[$key] ?? $this->defaultColumnWidths[$key] ?? self::MIN_COL_WIDTH;
    }

    private function computeDefaultColumnWidths(): void
    {
        foreach ($this->columns as $col) {
            $max = mb_strlen($col);
            foreach ($this->rows as $row) {
                $val = $row[$col] ?? null;
                $len = $val === null ? 4 : mb_strlen((string) $val);
                if ($len > $max) {
                    $max = $len;
                }
            }
            $this->defaultColumnWidths[$this->colKey($col)] = max(self::MIN_COL_WIDTH, min(self::MAX_COL_WIDTH, $max + 2));
        }
    }

    // ── Event handling ────────────────────────────────────────────────────

    public function handleCodedKey(string $code): void
    {
        match ($this->mode) {
            Mode::Tables  => $this->tablesCodedKey($code),
            Mode::Data    => $this->dataCodedKey($code),
            Mode::Row     => $this->rowCodedKey($code),
            Mode::Sql     => $this->sqlCodedKey($code),
            Mode::SqlRow  => $this->sqlRowCodedKey($code),
            Mode::Columns => $this->columnsCodedKey($code),
        };
    }

    public function handleCharKey(string $char, bool $ctrl = false): void
    {
        if ($ctrl && $char === 'c') {
            $this->running = false;
            return;
        }

        if ($ctrl && $char === 'e' && $this->mode !== Mode::Sql && $this->mode !== Mode::SqlRow) {
            $this->enterSqlMode($this->mode);
            return;
        }

        match ($this->mode) {
            Mode::Tables  => $this->tablesCharKey($char),
            Mode::Data    => $this->dataCharKey($char),
            Mode::Row     => $this->rowCharKey($char, $ctrl),
            Mode::Sql     => $this->sqlCharKey($char),
            Mode::SqlRow  => $this->sqlRowCharKey($char, $ctrl),
            Mode::Columns => $this->columnsCharKey($char),
        };
    }

    /**
     * @param string $kind  One of: ScrollUp, ScrollDown, Down, Up (MouseEventKind names)
     * @param int    $x     Column of click
     * @param int    $y     Row of click
     * @param int    $termW Terminal width (used to decide left/right panel)
     */
    public function handleMouse(string $kind, int $x, int $y, int $termW): void
    {
        $leftPanelWidth = 32; // matches the Renderer's Constraint::length(32)

        switch ($kind) {
            case 'ScrollUp':
                match ($this->mode) {
                    Mode::Tables => $this->tableUp(),
                    Mode::Data   => $this->rowUp(),
                    Mode::Row    => $this->rowUp(),
                    Mode::Sql    => $this->sqlResultUp(),
                    Mode::SqlRow => $this->sqlResultUp(),
                };
                break;

            case 'ScrollDown':
                match ($this->mode) {
                    Mode::Tables => $this->tableDown(),
                    Mode::Data   => $this->rowDown(),
                    Mode::Row    => $this->rowDown(),
                    Mode::Sql    => $this->sqlResultDown(),
                    Mode::SqlRow => $this->sqlResultDown(),
                };
                break;

            case 'Down': // left-click
                if ($this->mode === Mode::Sql || $this->mode === Mode::SqlRow) {
                    // SQL layout: 5-line input block + 1 border + 1 header = data rows start at y=7
                    $clicked = $y - 7;
                    if ($clicked >= 0 && $clicked < count($this->sqlRows)) {
                        $this->sqlRowIndex = $clicked;
                        $this->enterSqlRowDetail();
                    }
                    break;
                }
                if ($x < $leftPanelWidth) {
                    // Click in table list — row 1 is border, row 2+ are table names
                    $clicked = $y - 2; // subtract top border + header
                    if ($clicked >= 0 && $clicked < count($this->tables)) {
                        if ($this->tableIndex !== $clicked) {
                            $this->tableIndex = $clicked;
                            $this->loadTableData($this->selectedTable());
                        }
                    }
                    $this->mode = Mode::Tables;
                } else {
                    $this->ensureSelectedTableLoaded();

                    // Click in data panel — row 1 is border, row 2 is header, row 3+ are data
                    $clicked = $y - 3;
                    if ($clicked >= 0 && $clicked < count($this->rows)) {
                        if ($this->rowIndex === $clicked) {
                            $this->enterRowDetail();
                        } else {
                            $this->rowIndex = $clicked;
                        }
                    }
                    if ($this->mode !== Mode::Row) {
                        $this->mode = Mode::Data;
                    }
                }
                break;
        }
    }

    // ── Per-mode coded-key handlers ───────────────────────────────────────

    private function tablesCodedKey(string $code): void
    {
        switch ($code) {
            case 'Down':
                $this->tableDown();
                break;
            case 'Up':
                $this->tableUp();
                break;
            case 'Enter':
            case 'Tab':
            case 'Right':
                $this->ensureSelectedTableLoaded();
                $this->mode = Mode::Data;
                break;
        }
    }

    private function dataCodedKey(string $code): void
    {
        match ($code) {
            'Down'     => $this->rowDown(),
            'Up'       => $this->rowUp(),
            'Left'     => $this->mode = Mode::Tables,
            'Tab'      => $this->mode = Mode::Tables,
            'BackTab'  => $this->mode = Mode::Tables,
            'Enter'    => $this->enterRowDetail(),
            'PageDown' => $this->nextPage(),
            'PageUp'   => $this->prevPage(),
            'Home'     => $this->firstRow(),
            'End'      => $this->lastRow(),
            default    => null,
        };
    }

    private function rowCodedKey(string $code): void
    {
        if ($this->isEditing) {
            match ($code) {
                'Enter'     => $this->confirmEdit(),
                'Esc'       => $this->cancelEdit(),
                'Backspace' => $this->editBackspace(),
                'Delete'    => $this->editDelete(),
                'Left'      => $this->editCursor = max(0, $this->editCursor - 1),
                'Right'     => $this->editCursor = min(mb_strlen($this->editBuffer), $this->editCursor + 1),
                'Home'      => $this->editCursor = 0,
                'End'       => $this->editCursor = mb_strlen($this->editBuffer),
                default     => null,
            };
            return;
        }

        match ($code) {
            'Esc'   => $this->mode = Mode::Data,
            'Down'  => $this->editFieldIndex = min($this->editFieldIndex + 1, count($this->currentRow()) - 1),
            'Up'    => $this->editFieldIndex = max(0, $this->editFieldIndex - 1),
            'Enter' => $this->startEditing(),
            default => null,
        };
    }

    private function sqlCodedKey(string $code): void
    {
        match ($code) {
            'Esc'       => $this->closeSqlMode(),
            'Enter'     => $this->executeSql(),
            'Backspace' => $this->sqlBackspace(),
            'Tab'       => $this->acceptSqlSuggestion(),
            'Down'      => $this->sqlSuggestionDown(),
            'Up'        => $this->sqlSuggestionUp(),
            default     => null,
        };
    }

    // ── Per-mode char-key handlers ────────────────────────────────────────

    private function tablesCharKey(string $char): void
    {
        match ($char) {
            'q' => $this->running = false,
            's', ':' => $this->enterSqlMode(Mode::Tables),
            'j' => $this->tableDown(),
            'k' => $this->tableUp(),
            default => null,
        };
    }

    private function dataCharKey(string $char): void
    {
        match (true) {
            $char === 'q' => $this->running = false,
            $char === 's' || $char === ':' => $this->enterSqlMode(Mode::Data),
            $char === 'j' => $this->rowDown(),
            $char === 'k' => $this->rowUp(),
            $char === 'n' => $this->nextPage(),
            $char === 'p' => $this->prevPage(),
            $char === 'y' => $this->copyCurrentCell(),
            $char === 'Y' => $this->copyCurrentRow(),
            $char === 'c' => $this->enterColumnsMode(),
            $char === '[' => $this->scrollColumns(-1),
            $char === ']' => $this->scrollColumns(1),
            // 1–9: sort by that visible column (1-indexed)
            ctype_digit($char) && $char !== '0' => $this->toggleSort((int) $char - 1),
            default => null,
        };
    }

    private function rowCharKey(string $char, bool $ctrl = false): void
    {
        if ($this->isEditing) {
            if ($ctrl && $char === 'a') {
                $this->editCursor = 0;
                return;
            }

            if ($ctrl && $char === 'e') {
                $this->editCursor = mb_strlen($this->editBuffer);
                return;
            }

            if ($char === "\x7f") {
                $this->editBackspace();
                return;
            }
            $this->editInsert($char);
            return;
        }

        match ($char) {
            'q'     => $this->running = false,
            'j'     => $this->editFieldIndex = min($this->editFieldIndex + 1, count($this->currentRow()) - 1),
            'k'     => $this->editFieldIndex = max(0, $this->editFieldIndex - 1),
            'e'     => $this->startEditing(),
            ':'     => $this->enterSqlMode(Mode::Row),
            's'     => $this->saveRow(),
            'y'     => $this->copySelectedField(),
            default => null,
        };
    }

    private function sqlCharKey(string $char): void
    {
        // Backspace via DEL character (some terminals)
        if ($char === "\x7f") {
            $this->sqlBackspace();
            return;
        }

        if ($char === "\n" || $char === "\r") {
            return;
        }

        $this->sqlInput .= $char;
        $this->refreshSqlSuggestions();
    }

    private function sqlRowCodedKey(string $code): void
    {
        if ($this->isEditing) {
            match ($code) {
                'Enter'     => $this->confirmEdit(),
                'Esc'       => $this->cancelEdit(),
                'Backspace' => $this->editBackspace(),
                'Delete'    => $this->editDelete(),
                'Left'      => $this->editCursor = max(0, $this->editCursor - 1),
                'Right'     => $this->editCursor = min(mb_strlen($this->editBuffer), $this->editCursor + 1),
                'Home'      => $this->editCursor = 0,
                'End'       => $this->editCursor = mb_strlen($this->editBuffer),
                default     => null,
            };
            return;
        }

        match ($code) {
            'Esc'   => $this->mode = Mode::Sql,
            'Down'  => $this->editFieldIndex = min($this->editFieldIndex + 1, count($this->editTargetRow()) - 1),
            'Up'    => $this->editFieldIndex = max(0, $this->editFieldIndex - 1),
            'Enter' => $this->startEditing(),
            default => null,
        };
    }

    private function sqlRowCharKey(string $char, bool $ctrl = false): void
    {
        if ($this->isEditing) {
            if ($ctrl && $char === 'a') {
                $this->editCursor = 0;
                return;
            }

            if ($ctrl && $char === 'e') {
                $this->editCursor = mb_strlen($this->editBuffer);
                return;
            }

            if ($char === "\x7f") {
                $this->editBackspace();
                return;
            }
            $this->editInsert($char);
            return;
        }

        match ($char) {
            'q'     => $this->running = false,
            'j'     => $this->editFieldIndex = min($this->editFieldIndex + 1, count($this->editTargetRow()) - 1),
            'k'     => $this->editFieldIndex = max(0, $this->editFieldIndex - 1),
            'e'     => $this->startEditing(),
            's'     => $this->saveRow(),
            'y'     => $this->copySelectedField(),
            default => null,
        };
    }

    // ── Navigation helpers ────────────────────────────────────────────────

    private function tableDown(): void
    {
        if ($this->tableIndex < count($this->tables) - 1) {
            $this->tableIndex++;
            if ($this->mode !== Mode::Tables) {
                $this->loadTableData($this->selectedTable());
            }
        }
    }

    private function tableUp(): void
    {
        if ($this->tableIndex > 0) {
            $this->tableIndex--;
            if ($this->mode !== Mode::Tables) {
                $this->loadTableData($this->selectedTable());
            }
        }
    }

    private function rowDown(): void
    {
        if ($this->rowIndex < count($this->rows) - 1) {
            $this->rowIndex++;
        } elseif ($this->pageOffset() + count($this->rows) < $this->totalRows) {
            $this->nextPage();
            $this->rowIndex = 0;
        }
    }

    private function rowUp(): void
    {
        if ($this->rowIndex > 0) {
            $this->rowIndex--;
        } elseif ($this->dataPage > 0) {
            $this->prevPage();
            $this->rowIndex = count($this->rows) - 1;
        }
    }

    private function sqlResultDown(): void
    {
        if ($this->sqlRowIndex < count($this->sqlRows) - 1) {
            $this->sqlRowIndex++;
        }
    }

    private function sqlResultUp(): void
    {
        if ($this->sqlRowIndex > 0) {
            $this->sqlRowIndex--;
        }
    }

    /** @return array<string, mixed> */
    public function currentSqlRow(): array
    {
        return $this->sqlRows[$this->sqlRowIndex] ?? [];
    }

    private function nextPage(): void
    {
        if ($this->pageOffset() + $this->limit < $this->totalRows) {
            $this->dataPage++;
            $this->rowIndex = 0;
            $this->fetchRows();
        }
    }

    private function prevPage(): void
    {
        if ($this->dataPage > 0) {
            $this->dataPage--;
            $this->rowIndex = 0;
            $this->fetchRows();
        }
    }

    private function firstRow(): void
    {
        $this->dataPage = 0;
        $this->rowIndex = 0;
        $this->fetchRows();
    }

    private function lastRow(): void
    {
        $lastPage = (int) floor(max(0, $this->totalRows - 1) / $this->limit);
        if ($lastPage !== $this->dataPage) {
            $this->dataPage = $lastPage;
            $this->fetchRows();
        }
        $this->rowIndex = count($this->rows) - 1;
    }

    private function toggleSort(int $visiblePos): void
    {
        $visible = $this->visibleColumns();
        if ($visiblePos < 0 || $visiblePos >= count($visible)) {
            return;
        }

        $col = array_search($visible[$visiblePos], $this->columns, true);
        if ($col === false) {
            return;
        }

        if ($this->sortColumn === $col) {
            $this->sortDir = $this->sortDir === 'ASC' ? 'DESC' : 'ASC';
        } else {
            $this->sortColumn = $col;
            $this->sortDir    = 'ASC';
        }

        $this->dataPage = 0;
        $this->rowIndex = 0;
        $this->fetchRows();
    }

    // ── Column widths / visibility ───────────────────────────────────────────

    private function scrollColumns(int $delta): void
    {
        $max = max(0, count($this->visibleColumns()) - 1);
        $this->columnOffset = max(0, min($max, $this->columnOffset + $delta));
    }

    private function enterColumnsMode(): void
    {
        if ($this->selectedTable() === '' || empty($this->columns)) {
            return;
        }
        $this->mode           = Mode::Columns;
        $this->columnMgrIndex = 0;
    }

    private function columnsCodedKey(string $code): void
    {
        match ($code) {
            'Esc'   => $this->mode = Mode::Data,
            'Down'  => $this->columnMgrIndex = min($this->columnMgrIndex + 1, count($this->columns) - 1),
            'Up'    => $this->columnMgrIndex = max(0, $this->columnMgrIndex - 1),
            'Left'  => $this->resizeSelectedColumn(-2),
            'Right' => $this->resizeSelectedColumn(2),
            'Enter' => $this->toggleSelectedColumnHidden(),
            default => null,
        };
    }

    private function columnsCharKey(string $char): void
    {
        match ($char) {
            'q'       => $this->running = false,
            'j'       => $this->columnMgrIndex = min($this->columnMgrIndex + 1, count($this->columns) - 1),
            'k'       => $this->columnMgrIndex = max(0, $this->columnMgrIndex - 1),
            ' '       => $this->toggleSelectedColumnHidden(),
            '-'       => $this->resizeSelectedColumn(-2),
            '+', '='  => $this->resizeSelectedColumn(2),
            'r'       => $this->resetSelectedColumnWidth(),
            default   => null,
        };
    }

    private function selectedColumnName(): ?string
    {
        return $this->columns[$this->columnMgrIndex] ?? null;
    }

    private function toggleSelectedColumnHidden(): void
    {
        $col = $this->selectedColumnName();
        if ($col === null) {
            return;
        }

        $key = $this->colKey($col);
        if (!empty($this->hiddenColumns[$key])) {
            unset($this->hiddenColumns[$key]);
            return;
        }

        if (count($this->visibleColumns()) <= 1) {
            $this->statusMessage = 'At least one column must stay visible.';
            return;
        }

        $this->hiddenColumns[$key] = true;
    }

    private function resizeSelectedColumn(int $delta): void
    {
        $col = $this->selectedColumnName();
        if ($col === null) {
            return;
        }

        $current = $this->columnWidth($col);
        $new     = max(self::MIN_COL_WIDTH, min(self::MAX_MANUAL_COL_WIDTH, $current + $delta));
        $this->columnWidths[$this->colKey($col)] = $new;
    }

    private function resetSelectedColumnWidth(): void
    {
        $col = $this->selectedColumnName();
        if ($col === null) {
            return;
        }
        unset($this->columnWidths[$this->colKey($col)]);
    }

    // ── Row editing ───────────────────────────────────────────────────────

    private function enterRowDetail(): void
    {
        $this->mode           = Mode::Row;
        $this->editFieldIndex = 0;
        $this->isEditing      = false;
        $this->editBuffer     = '';
        $this->dirtyValues    = [];
        $this->saveMessage    = null;
    }

    private function enterSqlRowDetail(): void
    {
        $this->mode           = Mode::SqlRow;
        $this->editFieldIndex = 0;
        $this->isEditing      = false;
        $this->editBuffer     = '';
        $this->dirtyValues    = [];
        $this->saveMessage    = null;
    }

    /** The row currently shown in whichever detail view (Row or SqlRow) is active. */
    private function editTargetRow(): array
    {
        return $this->mode === Mode::SqlRow ? $this->currentSqlRow() : $this->currentRow();
    }

    /** The table edits should be written to, or null if it can't be determined safely. */
    private function editTargetTable(): ?string
    {
        return $this->mode === Mode::SqlRow ? $this->sqlResultTable : $this->selectedTable();
    }

    private function startEditing(): void
    {
        $row  = $this->editTargetRow();
        $keys = array_keys($row);
        $col  = $keys[$this->editFieldIndex] ?? null;
        if ($col === null) {
            return;
        }
        // Seed the buffer with the current (possibly already dirty) value
        $current          = array_key_exists($col, $this->dirtyValues)
            ? ($this->dirtyValues[$col] ?? '')
            : ($row[$col] === null ? '' : (string) $row[$col]);
        $this->editBuffer = $current;
        $this->editCursor = mb_strlen($this->editBuffer);
        $this->isEditing  = true;
        $this->saveMessage = null;
    }

    private function confirmEdit(): void
    {
        $row  = $this->editTargetRow();
        $keys = array_keys($row);
        $col  = $keys[$this->editFieldIndex] ?? null;
        if ($col === null) {
            return;
        }
        // Empty string stays as empty string; user can type the literal word NULL to store null
        $value = $this->editBuffer === 'NULL' ? null : $this->editBuffer;
        $this->dirtyValues[$col] = $value;
        $this->isEditing         = false;
        $this->editBuffer        = '';
        $this->editCursor        = 0;
    }

    private function cancelEdit(): void
    {
        $this->isEditing  = false;
        $this->editBuffer = '';
        $this->editCursor = 0;
    }

    private function editInsert(string $char): void
    {
        $before = mb_substr($this->editBuffer, 0, $this->editCursor);
        $after  = mb_substr($this->editBuffer, $this->editCursor);

        $this->editBuffer = $before . $char . $after;
        $this->editCursor += mb_strlen($char);
    }

    private function editBackspace(): void
    {
        if ($this->editCursor <= 0) {
            return;
        }

        $before = mb_substr($this->editBuffer, 0, $this->editCursor - 1);
        $after  = mb_substr($this->editBuffer, $this->editCursor);

        $this->editBuffer = $before . $after;
        $this->editCursor--;
    }

    private function editDelete(): void
    {
        if ($this->editCursor >= mb_strlen($this->editBuffer)) {
            return;
        }

        $before = mb_substr($this->editBuffer, 0, $this->editCursor);
        $after  = mb_substr($this->editBuffer, $this->editCursor + 1);

        $this->editBuffer = $before . $after;
    }

    private function saveRow(): void
    {
        if (empty($this->dirtyValues)) {
            $this->saveMessage = 'No changes to save.';
            return;
        }

        $table = $this->editTargetTable();
        if ($table === null || $table === '') {
            $this->saveMessage = 'Cannot save: this query is not editable (it spans multiple tables or none).';
            return;
        }

        try {
            $original = $this->editTargetRow();
            $affected = $this->db->updateRow($table, $original, $this->dirtyValues);

            $this->saveMessage = "Saved — {$affected} row updated.";

            if ($this->mode === Mode::SqlRow) {
                foreach ($this->dirtyValues as $col => $val) {
                    $this->sqlRows[$this->sqlRowIndex][$col] = $val;
                }
            } else {
                $this->invalidateRowsCache($table);
                $this->fetchRows();
            }

            $this->dirtyValues = [];
        } catch (\Throwable $e) {
            $this->saveMessage = 'Error: ' . $e->getMessage();
        }
    }

    // ── SQL mode ──────────────────────────────────────────────────────────

    private function enterSqlMode(Mode $returnMode): void
    {
        $this->sqlReturnMode      = $returnMode;
        $this->mode               = Mode::Sql;
        $this->sqlInput           = '';
        $this->sqlColumns         = [];
        $this->sqlRows            = [];
        $this->sqlError           = null;
        $this->sqlResultTable     = null;
        $this->sqlSuggestions     = [];
        $this->sqlSuggestionIndex = 0;
    }

    private function closeSqlMode(): void
    {
        $this->mode = $this->sqlReturnMode;
    }

    private function sqlBackspace(): void
    {
        if (mb_strlen($this->sqlInput) > 0) {
            $this->sqlInput = mb_substr($this->sqlInput, 0, -1);
            $this->refreshSqlSuggestions();
        }
    }

    private function executeSql(): void
    {
        $sql = trim($this->sqlInput);
        if ($sql === '') {
            return;
        }

        try {
            $result            = $this->db->executeRaw($sql);
            $this->sqlColumns  = $result['columns'];
            $this->sqlRows     = $result['rows'];
            $this->sqlRowIndex = 0;
            $this->sqlError    = null;
            $this->sqlResultTable = $this->detectSqlResultTable($sql);

            if (!$this->isReadOnlySql($sql)) {
                $this->invalidateAllDataCache();
            }
        } catch (\Throwable $e) {
            $this->sqlError    = $e->getMessage();
            $this->sqlColumns  = [];
            $this->sqlRows     = [];
            $this->sqlRowIndex = 0;
            $this->sqlResultTable = null;
        }
    }

    /**
     * Determine the single table a SELECT result can be safely written back to.
     * Returns null for non-SELECT statements, joins, or when the selected columns
     * don't map cleanly onto that table's real columns (aliases, expressions, etc).
     */
    private function detectSqlResultTable(string $sql): ?string
    {
        if (preg_match('/^\s*select\b/i', $sql) !== 1) {
            return null;
        }

        if (preg_match('/\bjoin\b/i', $sql) === 1) {
            return null;
        }

        if (preg_match('/\bfrom\s+`?"?([A-Za-z_][A-Za-z0-9_]*)`?"?/i', $sql, $m) !== 1) {
            return null;
        }

        $table = $m[1];
        if (!in_array($table, $this->tables, true)) {
            return null;
        }

        $tableColumns = $this->getColumnsCached($table);
        if (!empty($this->sqlColumns) && array_diff($this->sqlColumns, $tableColumns) !== []) {
            return null;
        }

        return $table;
    }

    private function sqlSuggestionDown(): void
    {
        if (empty($this->sqlSuggestions)) {
            $this->sqlResultDown();
            return;
        }

        $max = count($this->sqlSuggestions) - 1;
        $this->sqlSuggestionIndex = min($this->sqlSuggestionIndex + 1, $max);
    }

    private function sqlSuggestionUp(): void
    {
        if (empty($this->sqlSuggestions)) {
            $this->sqlResultUp();
            return;
        }

        $this->sqlSuggestionIndex = max(0, $this->sqlSuggestionIndex - 1);
    }

    private function acceptSqlSuggestion(): void
    {
        if (empty($this->sqlSuggestions)) {
            return;
        }

        $picked = $this->sqlSuggestions[$this->sqlSuggestionIndex] ?? null;
        if ($picked === null) {
            return;
        }

        // table_or_alias.col_prefix
        if (preg_match('/([A-Za-z_][A-Za-z0-9_]*\.)[A-Za-z0-9_]*$/', $this->sqlInput, $m) === 1) {
            $prefix = $m[1];
            $this->sqlInput = preg_replace('/([A-Za-z_][A-Za-z0-9_]*\.)[A-Za-z0-9_]*$/', $prefix . $picked, $this->sqlInput) ?? $this->sqlInput;
        } else {
            $this->sqlInput = preg_replace('/[A-Za-z0-9_]*$/', $picked, $this->sqlInput) ?? $this->sqlInput;
        }

        $this->sqlInput .= ' ';
        $this->refreshSqlSuggestions();
    }

    private function refreshSqlSuggestions(): void
    {
        $this->sqlSuggestions = $this->buildSqlSuggestions($this->sqlInput);
        if (empty($this->sqlSuggestions)) {
            $this->sqlSuggestionIndex = 0;
            return;
        }
        $this->sqlSuggestionIndex = min($this->sqlSuggestionIndex, count($this->sqlSuggestions) - 1);
    }

    /** @return string[] */
    private function buildSqlSuggestions(string $sql): array
    {
        $matches = [];

        // If user typed table_or_alias.<prefix>, suggest columns from that table.
        if (preg_match('/([A-Za-z_][A-Za-z0-9_]*)\.([A-Za-z0-9_]*)$/', $sql, $m) === 1) {
            $owner       = $m[1];
            $colPrefix   = strtolower($m[2]);
            $tableByName = $this->extractTableAliases($sql);
            $table       = $tableByName[$owner] ?? $owner;
            return $this->filterByPrefix($this->getColumnsCached($table), $colPrefix);
        }

        $prefix = $this->currentIdentifierPrefix($sql);
        $lowerPrefix = strtolower($prefix);

        if ($this->isExpectingTableName($sql)) {
            return $this->filterByPrefix($this->tables, $lowerPrefix);
        }

        if ($this->isExpectingColumnName($sql)) {
            $tables = $this->extractTableAliases($sql);

            if (empty($tables)) {
                $selected = $this->selectedTable();
                if ($selected !== '') {
                    $matches = $this->getColumnsCached($selected);
                }
            } else {
                foreach (array_unique(array_values($tables)) as $table) {
                    $matches = array_merge($matches, $this->getColumnsCached($table));
                }
            }

            $matches = array_values(array_unique($matches));
            sort($matches);
            return $this->filterByPrefix($matches, $lowerPrefix);
        }

        // Generic fallback: SQL keywords + table names.
        $keywords = [
            'SELECT', 'FROM', 'WHERE', 'JOIN', 'LEFT JOIN', 'RIGHT JOIN', 'INNER JOIN',
            'ORDER BY', 'GROUP BY', 'LIMIT', 'OFFSET', 'INSERT INTO', 'UPDATE', 'DELETE FROM',
        ];

        $base = array_merge($keywords, $this->tables);
        return $this->filterByPrefix($base, $lowerPrefix);
    }

    private function currentIdentifierPrefix(string $sql): string
    {
        if (preg_match('/([A-Za-z0-9_]*)$/', $sql, $m) !== 1) {
            return '';
        }

        return $m[1];
    }

    private function isExpectingTableName(string $sql): bool
    {
        return preg_match('/\b(from|join|update|into|table|describe|desc)\s+[A-Za-z0-9_]*$/i', $sql) === 1;
    }

    private function isExpectingColumnName(string $sql): bool
    {
        return preg_match('/\b(select|where|and|or|on|having|set|order\s+by|group\s+by)\s+[A-Za-z0-9_,\s]*$/i', $sql) === 1;
    }

    /**
     * @return array<string, string> alias_or_table => table
     */
    private function extractTableAliases(string $sql): array
    {
        $aliases = [];
        if (preg_match_all('/\b(from|join|update|into)\s+([A-Za-z_][A-Za-z0-9_]*)(?:\s+(?:as\s+)?([A-Za-z_][A-Za-z0-9_]*))?/i', $sql, $m, PREG_SET_ORDER) !== false) {
            foreach ($m as $match) {
                $table = $match[2];
                $alias = $match[3] ?? '';
                $aliases[$table] = $table;
                if ($alias !== '') {
                    $aliases[$alias] = $table;
                }
            }
        }

        return $aliases;
    }

    /** @return string[] */
    private function getColumnsCached(string $table): array
    {
        if ($table === '') {
            return [];
        }

        if (!isset($this->columnCache[$table])) {
            try {
                $this->columnCache[$table] = $this->db->getColumns($table);
            } catch (\Throwable) {
                $this->columnCache[$table] = [];
            }
        }

        return $this->columnCache[$table];
    }

    /** @param string[] $values
     *  @return string[]
     */
    private function filterByPrefix(array $values, string $prefix): array
    {
        if ($prefix === '') {
            return array_slice(array_values(array_unique($values)), 0, 10);
        }

        $filtered = array_values(array_filter(
            array_values(array_unique($values)),
            fn (string $v) => str_starts_with(strtolower($v), $prefix)
        ));

        return array_slice($filtered, 0, 10);
    }

    // ── Data loading ──────────────────────────────────────────────────────

    private function loadTableData(string $table): void
    {
        try {
            if (isset($this->tableMetaCache[$table])) {
                $meta = $this->tableMetaCache[$table];
            } else {
                $meta = [
                    'columns'   => $this->db->getColumns($table),
                    'totalRows' => $this->db->getRowCount($table),
                ];
                $this->tableMetaCache[$table] = $meta;
            }

            $this->columns      = $meta['columns'];
            $this->totalRows    = $meta['totalRows'];
            $this->dataPage     = 0;
            $this->rowIndex     = 0;
            $this->sortColumn   = null;
            $this->sortDir      = 'ASC';
            $this->columnOffset = 0;
            $this->fetchRows();
            $this->statusMessage = null;
            $this->loadedTable = $table;
        } catch (\Throwable $e) {
            $this->statusMessage = 'Error: ' . $e->getMessage();
            $this->columns       = [];
            $this->rows          = [];
            $this->totalRows     = 0;
            $this->loadedTable   = null;
        }
    }

    private function fetchRows(): void
    {
        try {
            $table   = $this->selectedTable();
            $sortCol = $this->sortColumn !== null ? ($this->columns[$this->sortColumn] ?? null) : null;
            $pageKey = $this->buildPageCacheKey($this->pageOffset(), $this->limit, $sortCol, $this->sortDir);

            if (isset($this->rowPageCache[$table][$pageKey])) {
                $this->rows = $this->rowPageCache[$table][$pageKey];
            } else {
                $rows = $this->db->getRows($table, $this->pageOffset(), $this->limit, $sortCol, $this->sortDir);
                $this->rowPageCache[$table][$pageKey] = $rows;
                $this->rows = $rows;
            }
            $this->statusMessage = null;
            $this->computeDefaultColumnWidths();
        } catch (\Throwable $e) {
            $this->statusMessage = 'Error: ' . $e->getMessage();
            $this->rows          = [];
        }
    }

    private function ensureSelectedTableLoaded(): void
    {
        $table = $this->selectedTable();
        if ($table === '' || $table === $this->loadedTable) {
            return;
        }

        $this->loadTableData($table);
    }

    private function buildPageCacheKey(int $offset, int $limit, ?string $sortCol, string $sortDir): string
    {
        return implode(':', [$offset, $limit, $sortCol ?? '', $sortDir]);
    }

    private function invalidateRowsCache(string $table): void
    {
        unset($this->rowPageCache[$table]);
    }

    private function invalidateAllDataCache(): void
    {
        $this->tableMetaCache = [];
        $this->rowPageCache   = [];
        $this->columnCache    = [];
        $this->loadedTable    = null;
    }

    private function isReadOnlySql(string $sql): bool
    {
        return (bool) preg_match('/^\s*(SELECT|WITH|SHOW|DESCRIBE|DESC|PRAGMA|EXPLAIN)\b/i', $sql);
    }

    // ── Copy to clipboard ─────────────────────────────────────────────────

    /**
     * Copy text to system clipboard.
     */
    private function copyToClipboard(string $text): bool
    {
        // Escape for shell
        $escaped = escapeshellarg($text);

        // Try pbcopy (macOS)
        if (file_exists('/usr/bin/pbcopy') || file_exists('/usr/local/bin/pbcopy')) {
            shell_exec("echo {$escaped} | pbcopy");
            return true;
        }

        // Try xclip (Linux)
        if (shell_exec('which xclip 2>/dev/null')) {
            shell_exec("echo {$escaped} | xclip -selection clipboard");
            return true;
        }

        // Try xsel (Linux)
        if (shell_exec('which xsel 2>/dev/null')) {
            shell_exec("echo {$escaped} | xsel --clipboard --input");
            return true;
        }

        // Try wl-copy (Wayland)
        if (shell_exec('which wl-copy 2>/dev/null')) {
            shell_exec("echo {$escaped} | wl-copy");
            return true;
        }

        return false;
    }

    /**
     * Copy the currently selected row in Data mode as tab-separated values.
     */
    private function copyCurrentCell(): void
    {
        $row = $this->currentRow();
        if (empty($row)) {
            $this->statusMessage = 'No row selected.';
            return;
        }

        $values = array_map(fn ($v) => $v === null ? 'NULL' : (string) $v, $row);
        $text = implode("\t", $values);

        if ($this->copyToClipboard($text)) {
            $count = count($row);
            $this->statusMessage = "Copied row ({$count} columns)";
        } else {
            $this->statusMessage = 'Failed to copy to clipboard.';
        }
    }

    /**
     * Copy the current row as JSON in Data mode.
     */
    private function copyCurrentRow(): void
    {
        $row = $this->currentRow();
        if (empty($row)) {
            $this->statusMessage = 'No row selected.';
            return;
        }

        $json = json_encode($row, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            $this->statusMessage = 'Failed to encode row as JSON.';
            return;
        }

        if ($this->copyToClipboard($json)) {
            $this->statusMessage = "Copied row as JSON";
        } else {
            $this->statusMessage = 'Failed to copy to clipboard.';
        }
    }

    /**
     * Copy the selected field value in Row detail view.
     */
    private function copySelectedField(): void
    {
        $row = $this->editTargetRow();
        if (empty($row)) {
            $this->statusMessage = 'No row selected.';
            return;
        }

        $keys = array_keys($row);
        if (!isset($keys[$this->editFieldIndex])) {
            $this->statusMessage = 'No field selected.';
            return;
        }

        $colName = $keys[$this->editFieldIndex];
        $isDirty = array_key_exists($colName, $this->dirtyValues);
        $value = $isDirty
            ? ($this->dirtyValues[$colName] ?? null)
            : ($row[$colName] ?? null);

        $textValue = $value === null ? 'NULL' : (string) $value;

        if ($this->copyToClipboard($textValue)) {
            $display = mb_strlen($textValue) > 50 ? mb_substr($textValue, 0, 50) . '…' : $textValue;
            $this->statusMessage = "Copied: {$display}";
        } else {
            $this->statusMessage = 'Failed to copy to clipboard.';
        }
    }

}
