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

namespace Hyn\Tenancy;

use Hyn\Tenancy\Contracts\CurrentHostname;
use Hyn\Tenancy\Contracts\Hostname;
use Hyn\Tenancy\Contracts\Tenant;
use Hyn\Tenancy\Contracts\Website;
use Hyn\Tenancy\Database\Connection;
use Hyn\Tenancy\Events;
use Hyn\Tenancy\Jobs\HostnameIdentification;
use Hyn\Tenancy\Traits\DispatchesEvents;
use Hyn\Tenancy\Traits\DispatchesJobs;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Traits\Macroable;

class Environment
{
    use DispatchesJobs, DispatchesEvents, Macroable;

    /**
     * @var Application
     */
    protected $app;

    /**
     * @var bool
     */
    protected $installed;

    public function __construct(Application $app)
    {
        $this->app = $app;
    }

    public function boot()
    {
        if (($this->forceEnabled() || $this->installed()) &&
            (!$this->app->runningInConsole() || $this->app->runningUnitTests()) &&
            config('tenancy.hostname.auto-identification')) {
            // Identifies the current hostname, sets it.
            $this->identifyHostname();
        }
    }

    public function installed(): bool
    {
        $isInstalled = function (): bool {
            /** @var \Illuminate\Database\Connection $connection */
            $connection = $this->app->make(Connection::class)->system();
            /** @var string $table */
            $table = $this->app->make(Website::class)->getTable();

            try {
                $tableExists = $connection->getSchemaBuilder()->hasTable($table);
            } finally {
                return $tableExists ?? false;
            }
        };

        return $this->installed ?? $this->installed = $isInstalled();
    }

    public function forceEnabled(): bool
    {
        return config('tenancy.force_enable');
    }

    public function identifyHostname()
    {
        /** @var Hostname $hostname */
        $hostname = $this->dispatch(new HostnameIdentification());

        if($hostname) {
            $hostname->website ? $this->tenant($hostname->website) : null;
            $this->app->instance(CurrentHostname::class, $hostname);
        }

        return $hostname;
    }

    /**
     * Get or set the current hostname.
     *
     * @param Hostname|null $hostname
     * @return Hostname|null
     */
    public function hostname(Hostname $hostname = null): ?Hostname
    {
        if ($hostname !== null) {
            $this->app->instance(CurrentHostname::class, $hostname);

            $this->emitEvent(new Events\Hostnames\Switched($hostname));

            return $hostname;
        }

        return $this->app->make(CurrentHostname::class);
    }

    public function website(): ?Website
    {
        $hostname = $this->hostname();

        return $hostname ? $hostname->website : null;
    }

    /**
     * Get or set current tenant.
     *
     * @param Website|null $website
     * @return Tenant|null
     */
    public function tenant(Website $website = null): ?Website
    {
        if ($website !== null) {
            $this->app->instance(Tenant::class, $website);

            $this->emitEvent(new Events\Websites\Switched($website));

            return $website;
        }

        return $this->app->make(Tenant::class);
    }
}
