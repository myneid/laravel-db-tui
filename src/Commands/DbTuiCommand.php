<?php

namespace Myneid\LaravelDbTui\Commands;

use Illuminate\Console\Command;
use Myneid\LaravelDbTui\Database\ConnectionStore;
use Myneid\LaravelDbTui\Database\PdoConnection;
use Myneid\LaravelDbTui\Tui\App;
use Myneid\LaravelDbTui\Tui\Renderer;

use PhpTui\Term\Terminal;
use PhpTui\Term\Actions;
use PhpTui\Term\Event\CharKeyEvent;
use PhpTui\Term\Event\CodedKeyEvent;
use PhpTui\Term\Event\MouseEvent;
use PhpTui\Term\KeyCode;
use PhpTui\Term\KeyModifiers;
use PhpTui\Term\MouseEventKind;
use PhpTui\Tui\Bridge\PhpTerm\PhpTermBackend;
use PhpTui\Tui\DisplayBuilder;

class DbTuiCommand extends Command
{
    protected $signature = 'db:tui
        {--connection=  : Laravel DB connection name (uses default when omitted)}
        {--url=         : Remote connection URL — mysql://user:pass@host/db, postgres://…, sqlite:/path}
        {--saved=       : Load a previously saved connection by name}
        {--save-as=     : Save the --url connection under this name for future use}
        {--list-saved   : List all saved connection names and exit}
        {--forget=      : Remove a saved connection by name and exit}';

    protected $description = 'Interactive TUI browser for your database — browse tables, sort, inspect rows, run SQL';

    public function handle(): int
    {
        if ($this->handleStoreCommands()) {
            return self::SUCCESS;
        }

        try {
            $db = $this->resolveConnection();
        } catch (\Throwable $e) {
            $this->error('Could not connect: ' . $e->getMessage());
            return self::FAILURE;
        }

        $app      = new App($db);
        $renderer = new Renderer();

        try {
            $app->init();
        } catch (\Throwable $e) {
            $this->error('Failed to load tables: ' . $e->getMessage());
            return self::FAILURE;
        }

        $terminal = Terminal::new();
        $display  = DisplayBuilder::default(PhpTermBackend::new($terminal))->build();

        $terminal->execute(Actions::cursorHide());
        $terminal->execute(Actions::alternateScreenEnable());
        $terminal->execute(Actions::enableMouseCapture());
        $terminal->enableRawMode();

        try {
            $this->runLoop($terminal, $display, $app, $renderer);
        } finally {
            $terminal->execute(Actions::disableMouseCapture());
            $terminal->disableRawMode();
            $terminal->execute(Actions::alternateScreenDisable());
            $terminal->execute(Actions::cursorShow());
        }

        return self::SUCCESS;
    }

    private function runLoop(
        Terminal  $terminal,
        mixed     $display,
        App       $app,
        Renderer  $renderer
    ): void {
        while ($app->running) {
            // Drain all pending events before re-rendering
            while (null !== $event = $terminal->events()->next()) {
                $this->dispatch($event, $app, $terminal);

                if (!$app->running) {
                    break;
                }
            }

            $display->draw($renderer->build($app));

            // ~60 fps — fast enough for a TUI, light enough for CPU
            usleep(16_000);
        }
    }

    private function dispatch(mixed $event, App $app, Terminal $terminal): void
    {
        if ($event instanceof CodedKeyEvent) {
            $app->handleCodedKey($this->codedKeyName($event->code));
            return;
        }

        if ($event instanceof CharKeyEvent) {
            $ctrl = (bool) ($event->modifiers & KeyModifiers::CONTROL);
            $app->handleCharKey($event->char, $ctrl);
            return;
        }

        if ($event instanceof MouseEvent) {
            // Terminal size API varies between php-tui minor versions
            try {
                $size  = $terminal->size();
                $termW = $size->columns ?? $size->width ?? 80;
            } catch (\Throwable) {
                $termW = 80;
            }

            $kindName = $this->mouseKindName($event->kind);
            // MouseEvent uses ->column / ->row (php-tui ≥ 0.2); older builds use ->x / ->y
            $col = $event->column ?? $event->x ?? 0;
            $row = $event->row    ?? $event->y ?? 0;

            if ($kindName !== null) {
                $app->handleMouse($kindName, $col, $row, $termW);
            }
        }
    }

    /** Map php-tui KeyCode enum cases to the plain string names used by App. */
    private function codedKeyName(KeyCode $code): string
    {
        return match ($code) {
            KeyCode::Up       => 'Up',
            KeyCode::Down     => 'Down',
            KeyCode::Left     => 'Left',
            KeyCode::Right    => 'Right',
            KeyCode::Enter    => 'Enter',
            KeyCode::Esc      => 'Esc',
            KeyCode::Tab      => 'Tab',
            KeyCode::BackTab  => 'BackTab',
            KeyCode::Backspace => 'Backspace',
            KeyCode::Delete   => 'Delete',
            KeyCode::Home     => 'Home',
            KeyCode::End      => 'End',
            KeyCode::PageUp   => 'PageUp',
            KeyCode::PageDown => 'PageDown',
            default           => 'Unknown',
        };
    }

    /** Map mouse event kinds to the strings App::handleMouse() understands. */
    private function mouseKindName(MouseEventKind $kind): ?string
    {
        return match ($kind) {
            MouseEventKind::ScrollUp   => 'ScrollUp',
            MouseEventKind::ScrollDown => 'ScrollDown',
            MouseEventKind::Down       => 'Down',   // left-click press
            default                    => null,
        };
    }

    /** Handle --list-saved / --forget / --save-as before opening the TUI. Returns true if we should exit. */
    private function handleStoreCommands(): bool
    {
        if ($this->option('list-saved')) {
            $all = ConnectionStore::all();
            if (empty($all)) {
                $this->line('No saved connections. Use --url=... --save-as=name to save one.');
                $this->line('Store: ' . ConnectionStore::storePath());
            } else {
                $this->line('Saved connections (' . ConnectionStore::storePath() . '):');
                foreach ($all as $name => $url) {
                    // Mask the password in the displayed URL
                    $display = preg_replace('#(://[^:]+:)[^@]+(@)#', '$1****$2', $url);
                    $this->line("  {$name}  →  {$display}");
                }
            }
            return true;
        }

        if ($forget = $this->option('forget')) {
            if (ConnectionStore::delete($forget)) {
                $this->info("Removed saved connection \"{$forget}\".");
            } else {
                $this->error("No saved connection named \"{$forget}\".");
            }
            return true;
        }

        return false;
    }

    private function resolveConnection(): PdoConnection
    {
        $url     = $this->option('url');
        $saveAs  = $this->option('save-as');
        $saved   = $this->option('saved');
        $laravel = $this->option('connection');

        // Load a saved URL
        if ($saved) {
            $url = ConnectionStore::get($saved);
            if ($url === null) {
                throw new \RuntimeException(
                    "No saved connection named \"{$saved}\". Run with --list-saved to see available names."
                );
            }
        }

        // Optionally persist the URL before connecting
        if ($url && $saveAs) {
            ConnectionStore::save($saveAs, $url);
            $this->info("Connection saved as \"{$saveAs}\" in " . ConnectionStore::storePath());
        }

        if ($url) {
            return PdoConnection::fromUrl($url);
        }

        // --connection can also resolve a saved URL by name
        if ($laravel && $savedUrl = ConnectionStore::get($laravel)) {
            return PdoConnection::fromUrl($savedUrl);
        }

        return PdoConnection::fromLaravel($laravel ?: null);
    }
}
