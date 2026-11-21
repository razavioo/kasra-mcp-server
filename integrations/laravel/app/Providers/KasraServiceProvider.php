<?php

namespace App\Providers;

use App\Services\Hr\Kasra\KasraClient;
use App\Services\Hr\Kasra\KasraMcpClient;
use Illuminate\Support\ServiceProvider;

class KasraServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(KasraClient::class, fn ($app, array $parameters) =>
            new KasraMcpClient($parameters['connection']));
    }
}
