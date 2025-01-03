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

namespace t2;

use ReflectionClass;
use RuntimeException;
use function array_merge;
use function array_reverse;
use function is_array;
use function method_exists;

class Middleware
{
    /**
     * @var array
     */
    protected static array $instances = [];

    /**
     * @param $allMiddlewares
     * @param string $plugin
     * @return void
     */
    public static function load($allMiddlewares, string $plugin = ''): void
    {
        if (!is_array($allMiddlewares)) {
            return;
        }

        foreach ($allMiddlewares as $appName => $middlewares) {
            if (!is_array($middlewares)) {
                throw new RuntimeException('Bad middleware config');
            }

            if ($appName === '@') {
                $plugin = '';
            }

            if (str_contains($appName, 'plugin.')) {
                $explode = explode('.', $appName, 4);
                $plugin = $explode[1];
                $appName = $explode[2] ?? '';
            }

            foreach ($middlewares as $className) {
                if (method_exists($className, 'process')) {
                    static::$instances[$plugin][$appName][] = [$className, 'process'];
                } else {
                    // @todo Log
                    echo "middleware $className::process not exsits\n";
                }
            }
        }
    }

    /**
     * @param string $plugin
     * @param string $appName
     * @param string $controller
     * @param bool $withGlobalMiddleware
     * @return array
     */
    public static function getMiddleware(string $plugin, string $appName, string $controller, bool $withGlobalMiddleware = true): array
    {
        $globalMiddleware = $withGlobalMiddleware ? static::$instances['']['@'] ?? [] : [];
        $appGlobalMiddleware = $withGlobalMiddleware && isset(static::$instances[$plugin]['']) ? static::$instances[$plugin][''] : [];
        $controllerMiddleware = [];

        if ($controller && class_exists($controller)) {
            $reflectionClass = new ReflectionClass($controller);
            if ($reflectionClass->hasProperty('middleware')) {
                $defaultProperties = $reflectionClass->getDefaultProperties();
                $controllerMiddlewareClasses = $defaultProperties['middleware'];
                foreach ((array)$controllerMiddlewareClasses as $className) {
                    if (method_exists($className, 'process')) {
                        $controllerMiddleware[] = [$className, 'process'];
                    }
                }
            }
        }

        if ($appName === '') {
            return array_reverse(array_merge($globalMiddleware, $appGlobalMiddleware, $controllerMiddleware));
        }
        $appMiddleware = static::$instances[$plugin][$appName] ?? [];

        return array_reverse(array_merge($globalMiddleware, $appGlobalMiddleware, $appMiddleware, $controllerMiddleware));
    }

    /**
     * @return void
     * @deprecated
     */
    public static function container($_)
    {

    }
}