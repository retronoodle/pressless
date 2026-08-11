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
}
