<?php
/**
 * This file is part of webman.
 *
 * Licensed under The MIT License
 * For full copyright and license information, please see the MIT-LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @author    walkor<walkor@workerman.net>
 * @copyright walkor<walkor@workerman.net>
 * @link      http://www.workerman.net/
 * @license   http://www.opensource.org/licenses/mit-license.php MIT License
 */

namespace app\bootstrap;

use Illuminate\Container\Container as IlluminateContainer;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\MySqlConnection;
use Illuminate\Events\Dispatcher;
use Illuminate\Pagination\Paginator;
use Illuminate\Pagination\CursorPaginator;
use Illuminate\Pagination\Cursor;
use Jenssegers\Mongodb\Connection as JenssegersMongodbConnection;
use MongoDB\Laravel\Connection as LaravelMongodbConnection;
use app\Container;
use Throwable;
use t2\Bootstrap;
use Workerman\Timer;
use Workerman\Worker;
use function class_exists;
use function config;

class LaravelDb implements Bootstrap
{
    /**
     * @param Worker|null $worker
     *
     * @return void
     */
    public static function start(?Worker $worker): void
    {
        if (!class_exists(Capsule::class)) {
            return;
        }

        $config = config('database', []);
        $connections = $config['connections'] ?? [];

        if (!$connections) {
            return;
        }

        $capsule = new Capsule(IlluminateContainer::getInstance());

        $capsule->getDatabaseManager()->extend('mongodb', function ($config, $name) {
            $config['name'] = $name;

            return class_exists(LaravelMongodbConnection::class) ? new LaravelMongodbConnection($config) : new JenssegersMongodbConnection($config);
        });

        $default = $config['default'] ?? false;
        $persistent = $config['persistent'] ?? true;
        if ($default) {
            $defaultConfig = $connections[$config['default']] ?? false;
            if ($defaultConfig) {
                $capsule->addConnection($defaultConfig);
            }
        }

        foreach ($connections as $name => $config) {
            $capsule->addConnection($config, $name);
        }

        if (class_exists(Dispatcher::class) && !$capsule->getEventDispatcher()) {
            $capsule->setEventDispatcher(Container::make(Dispatcher::class, [IlluminateContainer::getInstance()]));
        }

        $capsule->setAsGlobal();

        $capsule->bootEloquent();

        // Heartbeat
        if ($worker && $persistent) {
            Timer::add(55, function () use ($default, $connections, $capsule) {
                foreach ($capsule->getDatabaseManager()->getConnections() as $connection) {
                    /* @var MySqlConnection $connection * */
                    if ($connection->getConfig('driver') == 'mysql' && $connection->getRawPdo()) {
                        try {
                            $connection->select('select 1');
                        } catch (Throwable) {

                        }
                    }
                }
            });
        }

        // Paginator
        if (class_exists(Paginator::class)) {
            if (method_exists(Paginator::class, 'queryStringResolver')) {
                Paginator::queryStringResolver(function () {
                    $request = request();
                    return $request?->queryString();
                });
            }

            Paginator::currentPathResolver(function () {
                $request = request();
                return $request ? $request->path() : '/';
            });

            Paginator::currentPageResolver(function ($pageName = 'page') {
                $request = request();
                if (!$request) {
                    return 1;
                }

                $page = (int)($request->input($pageName, 1));

                return $page > 0 ? $page : 1;
            });

            if (class_exists(CursorPaginator::class)) {
                CursorPaginator::currentCursorResolver(function ($cursorName = 'cursor') {
                    return Cursor::fromEncoded(request()->input($cursorName));
                });
            }
        }
    }
}