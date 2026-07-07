<?php

namespace Myneid\LaravelDbTui\Tui;

use PhpTui\Tui\Extension\Core\Widget\BlockWidget;
use PhpTui\Tui\Extension\Core\Widget\GridWidget;
use PhpTui\Tui\Extension\Core\Widget\ListWidget;
use PhpTui\Tui\Extension\Core\Widget\List\ListItem;
use PhpTui\Tui\Extension\Core\Widget\List\ListState;
use PhpTui\Tui\Extension\Core\Widget\TableWidget;
use PhpTui\Tui\Extension\Core\Widget\Table\TableRow;
use PhpTui\Tui\Extension\Core\Widget\Table\TableCell;
use PhpTui\Tui\Extension\Core\Widget\ParagraphWidget;
use PhpTui\Tui\Layout\Constraint;
use PhpTui\Tui\Widget\Direction;
use PhpTui\Tui\Widget\Borders;
use PhpTui\Tui\Widget\BorderType;
use PhpTui\Tui\Style\Style;
use PhpTui\Tui\Style\Modifier;
use PhpTui\Tui\Color\AnsiColor;
use PhpTui\Tui\Text\Text;
use PhpTui\Tui\Text\Line;
use PhpTui\Tui\Text\Title;

class Renderer
{
    private const LEFT_WIDTH   = 32;
    private const TRUNCATE     = 48;
    private const DETAIL_WRAP  = 60;  // value column wrap width in row detail view
    private const SQL_MODAL_HEIGHT = 22;

    public function build(App $app, int $termWidth = 80): mixed
    {
        return match ($app->mode) {
            Mode::Tables,
            Mode::Data,
            Mode::Row     => $this->buildBrowse($app, $termWidth),
            Mode::Sql     => $this->buildSql($app),
            Mode::SqlRow  => $this->buildSqlRowDetail($app),
            Mode::Columns => $this->buildColumnsPopup($app),
        };
    }

    // ── Browse layout (Tables + Data/Row-detail) ──────────────────────────

    private function buildBrowse(App $app, int $termWidth): mixed
    {
        return GridWidget::default()
            ->direction(Direction::Vertical)
            ->constraints(
                Constraint::min(3),
                Constraint::length(1)
            )
            ->widgets(
                GridWidget::default()
                    ->direction(Direction::Horizontal)
                    ->constraints(
                        Constraint::length(self::LEFT_WIDTH),
                        Constraint::min(20)
                    )
                    ->widgets(
                        $this->buildTableList($app),
                        $app->mode === Mode::Row
                            ? $this->buildRowDetail($app)
                            : $this->buildDataTable($app, $termWidth)
                    ),
                $this->buildHelpBar($app)
            );
    }

    private function buildTableList(App $app): mixed
    {
        $focused = $app->mode === Mode::Tables;

        $items = array_map(
            fn (string $name) => ListItem::new(Text::fromString($name)),
            $app->tables
        );

        $inner = empty($items)
            ? ParagraphWidget::fromText(Text::fromString('(no tables)'))
            : ListWidget::default()
                ->highlightStyle(
                    Style::default()->fg(AnsiColor::Yellow)->addModifier(Modifier::BOLD)
                )
                ->highlightSymbol('▶ ')
                ->state(new ListState(0, $app->tableIndex))
                ->items(...$items);

        return BlockWidget::default()
            ->titles(Title::fromString($focused ? ' [Tables] ' : ' Tables '))
            ->borders(Borders::ALL)
            ->borderType($focused ? BorderType::Double : BorderType::Rounded)
            ->widget($inner);
    }

    private function buildDataTable(App $app, int $termWidth): mixed
    {
        $focused = $app->mode === Mode::Data;
        $table   = $app->selectedTable();

        if ($table === '') {
            return $this->emptyBlock(' Data ', 'Select a table from the left panel');
        }

        if (!$app->hasLoadedSelectedTable()) {
            return $this->emptyBlock(" {$table} ", 'Press Enter/Tab/→ to load this table.');
        }

        if ($app->statusMessage !== null) {
            return BlockWidget::default()
                ->titles(Title::fromString(' Error '))
                ->borders(Borders::ALL)
                ->borderType(BorderType::Double)
                ->widget(
                    ParagraphWidget::fromText(Text::fromString($app->statusMessage))
                );
        }

        if (empty($app->columns) || empty($app->rows)) {
            $msg = empty($app->columns) ? 'Could not read columns.' : 'No rows in this table.';
            return $this->emptyBlock(" {$table} ", $msg);
        }

        $visible = $app->visibleColumns();
        if (empty($visible)) {
            return $this->emptyBlock(" {$table} ", 'All columns are hidden. Press "c" to manage columns.');
        }

        // Fit as many fixed-width columns as possible, starting at columnOffset (horizontal scroll).
        $offset    = min($app->columnOffset, count($visible) - 1);
        $available = max(10, $termWidth - self::LEFT_WIDTH - 2);
        $shown     = [];
        $used      = 0;
        for ($i = $offset; $i < count($visible); $i++) {
            $col  = $visible[$i];
            $w    = $app->columnWidth($col);
            $sep  = empty($shown) ? 0 : 1;
            if ($used + $sep + $w > $available && !empty($shown)) {
                break;
            }
            $shown[] = $col;
            $used   += $sep + $w;
        }

        $hiddenBefore = $offset;
        $hiddenAfter  = count($visible) - $offset - count($shown);

        // Header row
        $headerCells = [];
        foreach ($shown as $col) {
            $label = $col;
            if ($app->sortColumn !== null && ($app->columns[$app->sortColumn] ?? null) === $col) {
                $label .= $app->sortDir === 'ASC' ? ' ↑' : ' ↓';
            }
            $headerCells[] = $this->cell(
                $label,
                Style::default()->addModifier(Modifier::BOLD)->fg(AnsiColor::LightCyan)
            );
        }

        // Data rows
        $tableRows = [];
        foreach ($app->rows as $i => $row) {
            $isSelected = $focused && ($i === $app->rowIndex);
            $cells = [];
            foreach ($shown as $col) {
                $val     = $row[$col] ?? null;
                $str     = $val === null ? 'NULL' : (string) $val;
                $str     = $this->truncate($str, self::TRUNCATE);
                $cells[] = $isSelected
                    ? $this->cell($str, Style::default()->addModifier(Modifier::REVERSED))
                    : TableCell::fromString($str);
            }
            $tableRows[] = TableRow::fromCells(...$cells);
        }

        $constraints = array_map(fn (string $col) => Constraint::length($app->columnWidth($col)), $shown);

        $start = $app->pageOffset() + 1;
        $end   = min($app->pageOffset() + count($app->rows), $app->totalRows);
        $colNote = ($hiddenBefore > 0 ? '←' . $hiddenBefore . ' ' : '') . ($hiddenAfter > 0 ? '→' . $hiddenAfter . ' ' : '');
        $title = $focused
            ? " [{$table}] (rows {$start}–{$end} of {$app->totalRows}) {$colNote}"
            : " {$table} (rows {$start}–{$end} of {$app->totalRows}) {$colNote}";

        $tableWidget = TableWidget::default()
            ->widths(...$constraints)
            ->header(TableRow::fromCells(...$headerCells))
            ->rows(...$tableRows);
        $tableWidget->columnSpacing = 1;

        return BlockWidget::default()
            ->titles(Title::fromString($title))
            ->borders(Borders::ALL)
            ->borderType($focused ? BorderType::Double : BorderType::Rounded)
            ->widget($tableWidget);
    }

    private function buildRowDetail(App $app): mixed
    {
        $row    = $app->currentRow();
        $rowNum = $app->pageOffset() + $app->rowIndex + 1;

        if (empty($row)) {
            return $this->emptyBlock(' Row detail ', 'No row selected.');
        }

        $hasDirty = !empty($app->dirtyValues);
        $title    = $hasDirty
            ? " Row {$rowNum} of {$app->totalRows}  [UNSAVED CHANGES] "
            : " Row {$rowNum} of {$app->totalRows} ";

        $statusText = $app->saveMessage
            ?? ($app->isEditing ? 'Arrows/Home/End: move  Backspace/Delete: edit  Enter: confirm  Esc: cancel' : 'e/Enter: edit field  s: save  Esc: back');

        return $this->buildRowDetailGrid($app, $row, $title, $statusText);
    }

    private function buildSqlRowDetail(App $app): mixed
    {
        $row    = $app->currentSqlRow();
        $rowNum = $app->sqlRowIndex + 1;
        $total  = count($app->sqlRows);

        if (empty($row)) {
            return $this->emptyBlock(' Row detail ', 'No row selected.');
        }

        $editable    = $app->sqlResultTable !== null;
        $hasDirty    = !empty($app->dirtyValues);
        $titleSuffix = $editable ? '' : '  [READ-ONLY]';
        $title       = $hasDirty
            ? " SQL Result — Row {$rowNum} of {$total}  [UNSAVED CHANGES] "
            : " SQL Result — Row {$rowNum} of {$total}{$titleSuffix} ";

        $statusText = $app->saveMessage
            ?? ($app->isEditing
                ? 'Arrows/Home/End: move  Backspace/Delete: edit  Enter: confirm  Esc: cancel'
                : ($editable
                    ? 'e/Enter: edit field  s: save  Esc: back to results'
                    : 'Read-only (query is not a single plain table)  Esc: back to results'));

        return $this->buildRowDetailGrid($app, $row, $title, $statusText);
    }

    /** @param array<string, mixed> $row */
    private function buildRowDetailGrid(App $app, array $row, string $title, string $statusText): mixed
    {
        $tableRows = [];
        $i = 0;
        foreach ($row as $col => $val) {
            $isFocused  = $i === $app->editFieldIndex;
            $isDirty    = array_key_exists($col, $app->dirtyValues);
            $isEditing  = $isFocused && $app->isEditing;

            // Column name cell — mark dirty fields with a bullet
            $colLabel    = ($isDirty ? '● ' : '  ') . $col;
            $colStyle    = $isFocused
                ? Style::default()->addModifier(Modifier::BOLD)->fg(AnsiColor::LightGreen)
                : Style::default()->addModifier(Modifier::BOLD)->fg(AnsiColor::LightYellow);
            $colCell     = $this->cell($colLabel, $colStyle);

            // Value cell
            if ($isEditing) {
                // Show the live edit buffer with a cursor
                $cursor = max(0, min($app->editCursor, mb_strlen($app->editBuffer)));
                $before = mb_substr($app->editBuffer, 0, $cursor);
                $after  = mb_substr($app->editBuffer, $cursor);
                $valueText  = Text::fromString($before . '█' . $after);
                $valueCell  = new TableCell($valueText, Style::default()->fg(AnsiColor::Black)->bg(AnsiColor::Yellow));
                $height     = 1;
            } else {
                // Show the dirty (pending) value or the original
                $display    = $isDirty
                    ? ($app->dirtyValues[$col] === null ? 'NULL' : (string) $app->dirtyValues[$col])
                    : ($val === null ? 'NULL' : (string) $val);

                $wrapped    = wordwrap($display, self::DETAIL_WRAP, "\n", true);
                $lines      = explode("\n", $wrapped);
                $height     = count($lines);
                $valueText  = Text::fromLines(...array_map(fn (string $l) => Line::fromString($l), $lines));
                $cellStyle  = $isFocused
                    ? Style::default()->addModifier(Modifier::REVERSED)
                    : ($isDirty ? Style::default()->fg(AnsiColor::Yellow) : Style::default());
                $valueCell  = new TableCell($valueText, $cellStyle);
            }

            $tableRows[] = TableRow::fromCells($colCell, $valueCell)->height($height);
            $i++;
        }

        return GridWidget::default()
            ->direction(Direction::Vertical)
            ->constraints(Constraint::min(3), Constraint::length(1))
            ->widgets(
                BlockWidget::default()
                    ->titles(Title::fromString($title))
                    ->borders(Borders::ALL)
                    ->borderType(BorderType::Double)
                    ->widget(
                        TableWidget::default()
                            ->widths(Constraint::percentage(28), Constraint::percentage(72))
                            ->header(
                                TableRow::fromCells(
                                    $this->cell('Column', Style::default()->addModifier(Modifier::BOLD)),
                                    $this->cell('Value',  Style::default()->addModifier(Modifier::BOLD))
                                )
                            )
                            ->rows(...$tableRows)
                    ),
                ParagraphWidget::fromText(Text::fromString(' ' . $statusText))
            );
    }

    // ── SQL layout ────────────────────────────────────────────────────────

    private function buildSql(App $app): mixed
    {
        $inputWidget = BlockWidget::default()
            ->titles(Title::fromString(' SQL Popup  Enter: run  Tab: complete  Esc: close '))
            ->borders(Borders::ALL)
            ->borderType(BorderType::Double)
            ->widget(
                ParagraphWidget::fromText(
                    // Append a block character as a simple cursor
                    Text::fromString($app->sqlInput . '█')
                )
            );

        $suggestions = $this->buildSqlSuggestions($app);

        $modal = GridWidget::default()
            ->direction(Direction::Vertical)
            ->constraints(
                Constraint::length(5),
                Constraint::length(4),
                Constraint::min(3),
                Constraint::length(1)
            )
            ->widgets(
                $inputWidget,
                $suggestions,
                $this->buildSqlResults($app),
                ParagraphWidget::fromText(Text::fromString(' Ctrl+E/:/s: open SQL | Enter: run | Tab: complete | ↑↓: pick suggestion/results | Esc: close '))
            );

        return GridWidget::default()
            ->direction(Direction::Vertical)
            ->constraints(
                Constraint::min(1),
                Constraint::length(self::SQL_MODAL_HEIGHT),
                Constraint::min(1)
            )
            ->widgets(
                ParagraphWidget::fromText(Text::fromString('')),
                GridWidget::default()
                    ->direction(Direction::Horizontal)
                    ->constraints(
                        Constraint::percentage(10),
                        Constraint::percentage(80),
                        Constraint::percentage(10)
                    )
                    ->widgets(
                        ParagraphWidget::fromText(Text::fromString('')),
                        BlockWidget::default()
                            ->titles(Title::fromString(' Query Runner '))
                            ->borders(Borders::ALL)
                            ->borderType(BorderType::Rounded)
                            ->widget($modal),
                        ParagraphWidget::fromText(Text::fromString(''))
                    ),
                ParagraphWidget::fromText(Text::fromString(''))
            );
    }

    // ── Columns popup ────────────────────────────────────────────────────

    private function buildColumnsPopup(App $app): mixed
    {
        $lines = [];
        foreach ($app->columns as $i => $col) {
            $hidden   = $app->isColumnHidden($col);
            $checkbox = $hidden ? '[ ]' : '[x]';
            $prefix   = $i === $app->columnMgrIndex ? '▶ ' : '  ';
            $suffix   = $hidden ? ' (hidden)' : '  width=' . $app->columnWidth($col);
            $lines[]  = "{$prefix}{$checkbox} {$col}{$suffix}";
        }

        $body = empty($lines)
            ? ParagraphWidget::fromText(Text::fromString('No columns.'))
            : ParagraphWidget::fromText(Text::fromString(implode("\n", $lines)));

        $modal = GridWidget::default()
            ->direction(Direction::Vertical)
            ->constraints(Constraint::min(3), Constraint::length(1))
            ->widgets(
                BlockWidget::default()
                    ->titles(Title::fromString(' Columns — ' . $app->selectedTable() . ' '))
                    ->borders(Borders::ALL)
                    ->borderType(BorderType::Rounded)
                    ->widget($body),
                ParagraphWidget::fromText(Text::fromString(
                    ' ↑↓/jk: select  Space/Enter: show/hide  ←→/-+: resize  r: reset width  Esc: close '
                ))
            );

        return GridWidget::default()
            ->direction(Direction::Vertical)
            ->constraints(
                Constraint::min(1),
                Constraint::length(self::SQL_MODAL_HEIGHT),
                Constraint::min(1)
            )
            ->widgets(
                ParagraphWidget::fromText(Text::fromString('')),
                GridWidget::default()
                    ->direction(Direction::Horizontal)
                    ->constraints(
                        Constraint::percentage(20),
                        Constraint::percentage(60),
                        Constraint::percentage(20)
                    )
                    ->widgets(
                        ParagraphWidget::fromText(Text::fromString('')),
                        BlockWidget::default()
                            ->titles(Title::fromString(' Manage Columns '))
                            ->borders(Borders::ALL)
                            ->borderType(BorderType::Double)
                            ->widget($modal),
                        ParagraphWidget::fromText(Text::fromString(''))
                    ),
                ParagraphWidget::fromText(Text::fromString(''))
            );
    }

    private function buildSqlSuggestions(App $app): mixed
    {
        if (empty($app->sqlSuggestions)) {
            return BlockWidget::default()
                ->titles(Title::fromString(' Suggestions '))
                ->borders(Borders::ALL)
                ->borderType(BorderType::Rounded)
                ->widget(ParagraphWidget::fromText(Text::fromString('No suggestions')));
        }

        $lines = [];
        foreach ($app->sqlSuggestions as $i => $suggestion) {
            $prefix  = $i === $app->sqlSuggestionIndex ? '▶ ' : '  ';
            $lines[] = $prefix . $suggestion;
        }

        return BlockWidget::default()
            ->titles(Title::fromString(' Suggestions '))
            ->borders(Borders::ALL)
            ->borderType(BorderType::Rounded)
            ->widget(ParagraphWidget::fromText(Text::fromString(implode("\n", $lines))));
    }

    private function buildSqlResults(App $app): mixed
    {
        if ($app->sqlError !== null) {
            return BlockWidget::default()
                ->titles(Title::fromString(' Error '))
                ->borders(Borders::ALL)
                ->borderType(BorderType::Rounded)
                ->widget(
                    ParagraphWidget::fromText(Text::fromString($app->sqlError))
                );
        }

        if (empty($app->sqlColumns)) {
            return $this->emptyBlock(
                ' Results ',
                'Results will appear here after you run a query.'
            );
        }

        $colCount    = count($app->sqlColumns);
        $pct         = (int) max(1, floor(100 / $colCount));
        $constraints = array_fill(0, $colCount, Constraint::percentage($pct));

        $headerCells = array_map(
            fn (string $col) => $this->cell(
                $col,
                Style::default()->addModifier(Modifier::BOLD)->fg(AnsiColor::LightCyan)
            ),
            $app->sqlColumns
        );

        $tableRows = [];
        foreach ($app->sqlRows as $i => $row) {
            $isSelected = $i === $app->sqlRowIndex;
            $cells = array_map(
                fn ($val) => $isSelected
                    ? $this->cell(
                        $val === null ? 'NULL' : $this->truncate((string) $val, self::TRUNCATE),
                        Style::default()->addModifier(Modifier::REVERSED)
                    )
                    : TableCell::fromString(
                        $val === null ? 'NULL' : $this->truncate((string) $val, self::TRUNCATE)
                    ),
                array_values($row)
            );
            $tableRows[] = TableRow::fromCells(...$cells);
        }

        $count = count($app->sqlRows);

        return BlockWidget::default()
            ->titles(Title::fromString(" Results ({$count} rows) "))
            ->borders(Borders::ALL)
            ->borderType(BorderType::Rounded)
            ->widget(
                TableWidget::default()
                    ->widths(...$constraints)
                    ->header(TableRow::fromCells(...$headerCells))
                    ->rows(...$tableRows)
            );
    }

    // ── Help bar ──────────────────────────────────────────────────────────

    private function buildHelpBar(App $app): mixed
    {
        $text = match ($app->mode) {
            Mode::Tables =>
                ' ↑↓/jk: table | Enter/Tab/→: load table | :/s/Ctrl+E: SQL popup | q: quit',
            Mode::Data =>
                ' ↑↓/jk: row | Tab/←: tables | Enter: detail | [/]: scroll cols | c: columns | y: copy row | Y: copy as JSON | 1-9: sort | PgUp/n PgDn/p: page | :/s/Ctrl+E: SQL popup | q: quit',
            Mode::Row =>
                ' ↑↓/jk: field  e/Enter: edit  s: save  :/Ctrl+E: SQL popup  y: copy  Esc: back  q: quit  (type NULL to store null)',
            Mode::Sql =>
                ' Type SQL | Tab: complete | Enter: execute | click row: detail | ↑↓: suggest/results | Esc: close popup',
            Mode::SqlRow =>
                ' ↑↓/jk: field  e/Enter: edit  s: save  y: copy  Esc: back to results  q: quit  (type NULL to store null)',
            Mode::Columns =>
                ' ↑↓/jk: select  Space/Enter: show/hide  ←→/-+: resize  r: reset width  Esc: close  q: quit',
        };

        return ParagraphWidget::fromText(Text::fromString($text));
    }

    // ── Utilities ─────────────────────────────────────────────────────────

    /** TableCell has no style() chain method — style goes in the constructor. */
    private function cell(string $str, Style $style): TableCell
    {
        return new TableCell(Text::fromLine(Line::fromString($str)), $style);
    }

    private function emptyBlock(string $title, string $message): mixed
    {
        return BlockWidget::default()
            ->titles(Title::fromString($title))
            ->borders(Borders::ALL)
            ->borderType(BorderType::Rounded)
            ->widget(ParagraphWidget::fromText(Text::fromString($message)));
    }

    private function truncate(string $str, int $max): string
    {
        if (mb_strlen($str) <= $max) {
            return $str;
        }
        return mb_substr($str, 0, $max - 1) . '…';
    }
}
