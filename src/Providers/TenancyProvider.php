<?php

/*
 * This file is part of the hyn/multi-tenant package.
 *
 * (c) Daniël Klabbers <daniel@klabbers.email>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @see https://laravel-tenancy.com
 * @see https://github.com/hyn/multi-tenant
 */

namespace Hyn\Tenancy\Providers;

use Hyn\Tenancy\Commands\RecreateCommand;
use Hyn\Tenancy\Contracts;
use Hyn\Tenancy\Contracts\Hostname as HostnameContract;
use Hyn\Tenancy\Contracts\Website as WebsiteContract;
use Hyn\Tenancy\Environment;
use Hyn\Tenancy\Listeners\Database\FlushHostnameCache;
use Hyn\Tenancy\Middleware;
use Hyn\Tenancy\Providers\Tenants as Providers;
use Hyn\Tenancy\Repositories;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Support\ServiceProvider;

class TenancyProvider extends ServiceProvider
{
    public function register()
    {
        $this->mergeConfigFrom(
            __DIR__ . '/../../assets/configs/tenancy.php',
            'tenancy'
        );

        $this->publishes(
            [__DIR__ . '/../../assets/migrations' => database_path('migrations')],
            'tenancy'
        );

        $this->registerModels();

        $this->registerMiddleware();

        $this->registerRepositories();

        $this->registerDefaults();

        $this->registerProviders();

        $this->registerEnviroment();
    }

    public function boot()
    {
        $this->bootCommands();

        $this->bootEnvironment();
    }

    public function provides()
    {
        return [Environment::class];
    }

    protected function registerModels()
    {
        $config = $this->app['config']['tenancy.models'];

        $this->app->bind(HostnameContract::class, $config['hostname']);
        $this->app->bind(WebsiteContract::class, $config['website']);

        forward_static_call([$config['hostname'], 'observe'], FlushHostnameCache::class);
    }

    protected function registerRepositories()
    {
        $this->app->singleton(
            Contracts\Repositories\HostnameRepository::class,
            Repositories\HostnameRepository::class
        );
        $this->app->singleton(
            Contracts\Repositories\WebsiteRepository::class,
            Repositories\WebsiteRepository::class
        );
    }

    protected function registerProviders()
    {
        $this->app->register(Providers\ConfigurationProvider::class);
        $this->app->register(Providers\PasswordProvider::class);
        $this->app->register(Providers\ConnectionProvider::class);
        $this->app->register(Providers\UuidProvider::class);
        $this->app->register(Providers\BusProvider::class);
        $this->app->register(Providers\FilesystemProvider::class);
        $this->app->register(Providers\HostnameProvider::class);

        // Register last.
        $this->app->register(Providers\EventProvider::class);
        $this->app->register(Providers\RouteProvider::class);
    }

    public function registerDefaults()
    {
        $empty = function () {
            return null;
        };

        $this->app->singleton(Contracts\Tenant::class, $empty);
        $this->app->singleton(Contracts\CurrentHostname::class, $empty);
    }

    public function registerEnviroment()
    {
        // Immediately instantiate the object to work the magic.
        $environment = $this->app->make(Environment::class);
        // Now register it into ioc to make it globally available.
        $this->app->singleton(Environment::class, function () use ($environment) {
            return $environment;
        });

        $this->app->alias(Environment::class, 'tenancy-environment');
    }

    protected function bootCommands()
    {
        $this->commands(RecreateCommand::class);
    }

    protected function bootEnvironment()
    {
        $this->app->make(Environment::class)->boot();
    }

    protected function registerMiddleware()
    {
        /** @var Kernel|\Illuminate\Foundation\Http\Kernel $kernel */
        $kernel = $this->app->make(Kernel::class);

        $config               = config();
        $eager_identification = $config->get('tenancy.hostname.early-identification');
        $abort_without        = $config->get('tenancy.hostname.abort-without-identified-hostname');


        if ($eager_identification) {
            $kernel->prependMiddleware(Middleware\EagerIdentification::class);
        }

        if ($eager_identification || $abort_without) {
            $kernel->prependMiddleware(Middleware\HostnameActions::class);
        }
    }
}
