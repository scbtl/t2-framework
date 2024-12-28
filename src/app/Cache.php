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

namespace app;

use RedisException;
use Symfony\Component\Cache\Adapter\RedisAdapter;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Adapter\PdoAdapter;
use Symfony\Component\Cache\Psr16Cache;
use InvalidArgumentException;

/**
 * Class Cache
 * @package support\bootstrap
 *
 * Strings methods
 * @method static mixed get($key, $default = null)
 * @method static bool set($key, $value, $ttl = null)
 * @method static bool delete($key)
 * @method static bool clear()
 * @method static iterable getMultiple($keys, $default = null)
 * @method static bool setMultiple($values, $ttl = null)
 * @method static bool deleteMultiple($keys)
 * @method static bool has($key)
 */
class Cache
{
    /**
     * @var Psr16Cache[]
     */
    public static $instances = [];

    /**
     * @param string|null $name
     * @return Psr16Cache
     * @throws RedisException
     */
    public static function store(?string $name = null): Psr16Cache
    {
        $name = $name ?: config('cache.default', 'redis');
        $stores = !config('cache') ? [
            'redis' => [
                'driver'     => 'redis',
                'connection' => 'default'
            ],
        ] : config('cache.stores', []);
        if (!isset($stores[$name])) {
            throw new InvalidArgumentException("cache.store.$name is not defined. Please check config/cache.php");
        }
        if (!isset(static::$instances[$name])) {
            $driver = $stores[$name]['driver'];
            switch ($driver) {
                case 'redis':
                    $client = Redis::connection($stores[$name]['connection'])->client();
                    $adapter = new RedisAdapter($client);
                    break;
                case 'file':
                    $adapter = new FilesystemAdapter('', 0, $stores[$name]['path']);
                    break;
                case 'array':
                    $adapter = new ArrayAdapter(0, $stores[$name]['serialize'] ?? false, 0, 0);
                    break;
                /**
                 * Pdo can not reconnect when the connection is lost. So we can not use pdo as cache.
                 */
                /*case 'database':
                    $adapter = new PdoAdapter(Db::connection($stores[$name]['connection'])->getPdo());
                    break;*/
                default:
                    throw new InvalidArgumentException("cache.store.$name.driver=$driver is not supported.");
            }
            static::$instances[$name] = new Psr16Cache($adapter);
        }

        return static::$instances[$name];
    }

    /**
     * @param $name
     * @param $arguments
     * @return mixed
     * @throws RedisException
     */
    public static function __callStatic($name, $arguments)
    {
        return static::store()->{$name}(... $arguments);
    }
}