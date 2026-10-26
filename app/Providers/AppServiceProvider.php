<?php

namespace App\Providers;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Everyone shares one Supabase database, so block the commands that wipe it:
        // migrate:fresh, migrate:refresh, migrate:reset and db:wipe.
        // Normal "php artisan migrate" still works.
        DB::prohibitDestructiveCommands();
    }
}