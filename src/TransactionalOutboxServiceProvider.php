<?php

namespace Urfysoft\TransactionalOutbox;

use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use Urfysoft\TransactionalOutbox\Commands\TransactionalOutboxCommand;

class TransactionalOutboxServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        /*
         * This class is a Package Service Provider
         *
         * More info: https://github.com/spatie/laravel-package-tools
         */
        $package
            ->name('transactional-outbox')
            ->hasConfigFile()
            ->hasViews()
            ->hasMigration('create_transactional_outbox_table')
            ->hasCommand(TransactionalOutboxCommand::class);
    }
}
