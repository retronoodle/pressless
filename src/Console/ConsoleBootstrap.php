<?php

declare(strict_types=1);

namespace Stead\Console;

use Stead\Bootstrap\Application;
use Symfony\Component\Console\Application as ConsoleApplication;

final class ConsoleBootstrap
{
    /**
     * Builds the console for `bin/serve`. The `serve` command is the default
     * and the only exposed command, so the executable name matches its
     * behavior and no other commands can be invoked by accident.
     */
    public static function forServe(Application $app): ConsoleApplication
    {
        $console = new ConsoleApplication('Stead', '0.1.0');
        $serve = new ServeCommand($app);
        $console->add($serve);
        $console->setDefaultCommand($serve->getName(), true);

        return $console;
    }

    /**
     * Builds the console for `bin/migrate`. The `migrate` command is the default
     * and the only exposed command, so a `bin/migrate` invocation cannot ever
     * accidentally start the development server.
     */
    public static function forMigrate(Application $app): ConsoleApplication
    {
        $console = new ConsoleApplication('Stead', '0.1.0');
        $migrate = new MigrateCommand($app);
        $console->add($migrate);
        $console->setDefaultCommand($migrate->getName(), true);

        return $console;
    }

    /**
     * Builds the console for `bin/release`. The `release` command is the default
     * and the only exposed command, so a `bin/release` invocation cannot ever
     * accidentally start the development server or run migrations.
     *
     * The release command itself does not need the database; the bootstrap
     * is still run so a missing/misconfigured project surfaces early instead
     * of half-way through `composer install`.
     */
    public static function forRelease(Application $app): ConsoleApplication
    {
        $console = new ConsoleApplication('Stead', '0.1.0');
        $release = new ReleaseCommand($app);
        $console->add($release);
        $console->setDefaultCommand($release->getName(), true);

        return $console;
    }

    /**
     * Builds the console for `bin/backup`. Both the manual `backup`
     * command and the `backup:restore` subcommand are exposed so a
     * `bin/backup` invocation cannot accidentally start the development
     * server or run migrations.
     */
    public static function forBackup(Application $app): ConsoleApplication
    {
        $console = new ConsoleApplication('Stead', '0.1.0');
        $backup = new BackupCommand($app);
        $console->add($backup);
        $console->add(new RestoreCommand($app));
        $console->setDefaultCommand($backup->getName());

        return $console;
    }
}
