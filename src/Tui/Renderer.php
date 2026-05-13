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
use PhpTui\Tui\Text\Span;
use PhpTui\Tui\Text\Title;

class Renderer
{
    private const LEFT_WIDTH   = 32;
    private const TRUNCATE     = 48;
    private const DETAIL_WRAP  = 60;  // value column wrap width in row detail view

    public function build(App $app): mixed
    {
        return match ($app->mode) {
            Mode::Tables,
            Mode::Data,
            Mode::Row   => $this->buildBrowse($app),
            Mode::Sql   => $this->buildSql($app),
            Mode::SqlRow => $this->buildSqlRowDetail($app),
        };
    }

    // ── Browse layout (Tables + Data/Row-detail) ──────────────────────────

    private function buildBrowse(App $app): mixed
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
                            : $this->buildDataTable($app)
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

    private function buildDataTable(App $app): mixed
    {
        $focused = $app->mode === Mode::Data;
        $table   = $app->selectedTable();

        if ($table === '') {
            return $this->emptyBlock(' Data ', 'Select a table from the left panel');
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

        $colCount = count($app->columns);

        if ($colCount === 0 || empty($app->rows)) {
            $msg = $colCount === 0 ? 'Could not read columns.' : 'No rows in this table.';
            return $this->emptyBlock(" {$table} ", $msg);
        }

        // Header row
        $headerCells = [];
        foreach ($app->columns as $i => $col) {
            $label = $col;
            if ($app->sortColumn === $i) {
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
            foreach (array_values($row) as $val) {
                $str     = $val === null ? 'NULL' : (string) $val;
                $str     = $this->truncate($str, self::TRUNCATE);
                $cells[] = $isSelected
                    ? $this->cell($str, Style::default()->addModifier(Modifier::REVERSED))
                    : TableCell::fromString($str);
            }
            $tableRows[] = TableRow::fromCells(...$cells);
        }

        // Distribute columns evenly
        $pct         = (int) max(1, floor(100 / $colCount));
        $constraints = array_fill(0, $colCount, Constraint::percentage($pct));

        $start = $app->pageOffset() + 1;
        $end   = min($app->pageOffset() + count($app->rows), $app->totalRows);
        $title = $focused
            ? " [{$table}] (rows {$start}–{$end} of {$app->totalRows}) "
            : " {$table} (rows {$start}–{$end} of {$app->totalRows}) ";

        return BlockWidget::default()
            ->titles(Title::fromString($title))
            ->borders(Borders::ALL)
            ->borderType($focused ? BorderType::Double : BorderType::Rounded)
            ->widget(
                TableWidget::default()
                    ->widths(...$constraints)
                    ->header(TableRow::fromCells(...$headerCells))
                    ->rows(...$tableRows)
            );
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

        $fieldKeys  = array_keys($row);
        $tableRows  = [];

        foreach ($row as $i => $col) {
            // $i is the column name here (from foreach with string keys)
        }

        // Rebuild with proper index tracking
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
                $valueText  = Text::fromString($app->editBuffer . '█');
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

        // Status line (save feedback or editing hint)
        $statusText = $app->saveMessage
            ?? ($app->isEditing ? 'Enter: confirm  Esc: cancel edit' : 'e/Enter: edit field  s: save  Esc: back');

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
            ->titles(Title::fromString(' SQL  Enter: run  |  Esc: back '))
            ->borders(Borders::ALL)
            ->borderType(BorderType::Double)
            ->widget(
                ParagraphWidget::fromText(
                    // Append a block character as a simple cursor
                    Text::fromString($app->sqlInput . '█')
                )
            );

        return GridWidget::default()
            ->direction(Direction::Vertical)
            ->constraints(
                Constraint::length(5),
                Constraint::min(3),
                Constraint::length(1)
            )
            ->widgets(
                $inputWidget,
                $this->buildSqlResults($app),
                $this->buildHelpBar($app)
            );
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

    private function buildSqlRowDetail(App $app): mixed
    {
        $row    = $app->currentSqlRow();
        $rowNum = $app->sqlRowIndex + 1;
        $total  = count($app->sqlRows);

        if (empty($row)) {
            return $this->emptyBlock(' Row detail ', 'No row selected.');
        }

        $tableRows = [];
        foreach ($row as $col => $val) {
            $str     = $val === null ? 'NULL' : (string) $val;
            $wrapped = wordwrap($str, self::DETAIL_WRAP, "\n", true);
            $lines   = explode("\n", $wrapped);
            $height  = count($lines);

            $valueText = Text::fromLines(
                ...array_map(fn (string $l) => Line::fromString($l), $lines)
            );

            $tableRows[] = TableRow::fromCells(
                $this->cell((string) $col, Style::default()->addModifier(Modifier::BOLD)->fg(AnsiColor::LightYellow)),
                new TableCell($valueText, Style::default())
            )->height($height);
        }

        $helpBar = ParagraphWidget::fromText(
            Text::fromString(' Esc: back to SQL results | ↑↓/jk: prev/next row | q: quit')
        );

        return GridWidget::default()
            ->direction(Direction::Vertical)
            ->constraints(Constraint::min(3), Constraint::length(1))
            ->widgets(
                BlockWidget::default()
                    ->titles(Title::fromString(" SQL Result — Row {$rowNum} of {$total} "))
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
                $helpBar
            );
    }

    // ── Help bar ──────────────────────────────────────────────────────────

    private function buildHelpBar(App $app): mixed
    {
        $text = match ($app->mode) {
            Mode::Tables =>
                ' ↑↓/jk: table | Enter/Tab/→: data | s: SQL | q: quit',
            Mode::Data =>
                ' ↑↓/jk: row | Tab/←: tables | Enter: detail | 1-9: sort | PgUp/n PgDn/p: page | s: SQL | q: quit',
            Mode::Row =>
                ' ↑↓/jk: field  e/Enter: edit  s: save  Esc: back  q: quit  (type NULL to store null)',
            Mode::Sql =>
                ' Type SQL | Enter: execute | click row: detail | ↑↓/jk: navigate results | Esc: back',
            Mode::SqlRow =>
                ' Esc: back to SQL results | ↑↓/jk: prev/next row | q: quit',
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
