<?php

namespace App\Providers;

use App\Mail\BrevoTransport;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
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

        // MAIL_MAILER=brevo: send email through Brevo's web API (HTTPS), since Render's free plan blocks SMTP
        Mail::extend('brevo', fn () => new BrevoTransport((string) config('services.brevo.key')));
    }
}