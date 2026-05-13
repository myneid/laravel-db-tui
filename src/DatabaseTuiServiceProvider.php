<?php

namespace Myneid\LaravelDbTui;

use Illuminate\Support\ServiceProvider;
use Myneid\LaravelDbTui\Commands\DbTuiCommand;

class DatabaseTuiServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([DbTuiCommand::class]);
        }
    }
}
