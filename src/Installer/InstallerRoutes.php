<?php

declare(strict_types=1);

namespace Stead\Installer;

use Stead\Http\Router;
use Stead\Installer\Controller\InstallerController;

/**
 * Builds the route table for the installer wizard.
 *
 * The installer routes are isolated from the application's normal {@see Routes}
 * so the front controller can hand a single, focused router to the installer
 * kernel without bootstrapping the database, session handler, or any other
 * service the installer does not need.
 */
final class InstallerRoutes
{
    public static function create(InstallerController $controller): Router
    {
        $router = new Router();
        $router->get('/install', $controller->welcome(...), 'installer.welcome');
        $router->get('/install/database', $controller->showDatabase(...), 'installer.database.show');
        $router->post('/install/database', $controller->submitDatabase(...), 'installer.database.submit');
        $router->get('/install/admin', $controller->showAdmin(...), 'installer.admin.show');
        $router->post('/install/admin', $controller->submitAdmin(...), 'installer.admin.submit');
        $router->get('/install/sample-data', $controller->showSampleData(...), 'installer.sample-data.show');
        $router->post('/install/sample-data', $controller->submitSampleData(...), 'installer.sample-data.submit');
        $router->get('/install/complete', $controller->complete(...), 'installer.complete');
        $router->post('/install/complete', $controller->complete(...), 'installer.complete.submit');
        return $router;
    }
}