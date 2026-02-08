<?php

namespace UoGSoE\LaravelInit;

use Illuminate\Support\ServiceProvider;
use UoGSoE\LaravelInit\Commands\ProjectInitCommand;

class LaravelInitServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                ProjectInitCommand::class,
            ]);
        }
    }
}
