<?php

namespace App\Http\Middleware;

use Illuminate\Http\Middleware\TrustProxies;

/**
 * Behind the host's proxy / load balancer: use the visitor's real IP (activity log, rate limits)
 * and know the site is on HTTPS. Set TRUSTED_PROXIES in .env:
 *   TRUSTED_PROXIES=*                  the host puts a proxy in front of the app (most cloud hosting)
 *   TRUSTED_PROXIES=10.0.0.1,10.0.0.2  only these proxy addresses
 *   (empty)                            no proxy: the app is reached directly
 * Read at request time (not at start-up), so it also works after `php artisan config:cache`.
 */
class TrustHostProxies extends TrustProxies
{
    protected function proxies()
    {
        $p = trim((string) config('app.trusted_proxies', ''));
        if ($p === '') return null;
        return $p === '*' ? '*' : array_values(array_filter(array_map('trim', explode(',', $p))));
    }
}