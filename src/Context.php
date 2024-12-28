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

use Fiber;
use SplObjectStorage;
use StdClass;
use Swow\Coroutine;
use WeakMap;
use Workerman\Events\Revolt;
use Workerman\Events\Swoole;
use Workerman\Events\Swow;
use Workerman\Worker;
use function property_exists;

/**
 * Context 类用于在不同的事件循环中存储和获取上下文数据
 * 提供了类似于协程上下文的功能
 */
class Context
{
    /**
     * 存储对象的容器，使用 WeakMap 或 SplObjectStorage
     * WeakMap 可以帮助防止内存泄漏，因为它不会阻止对象被垃圾回收
     * @var WeakMap|SplObjectStorage|null
     */
    protected static WeakMap|SplObjectStorage|null $objectStorage = null;

    /**
     * 存储每个上下文的对象
     * @var StdClass|null
     */
    protected static ?StdClass $object = null;

    /**
     * 初始化静态属性，如果尚未初始化
     * 根据当前环境选择使用 WeakMap 还是 SplObjectStorage
     * WeakMap 更适用于协程环境，因为它能够自动清理对象，防止内存泄漏
     *
     * @return void
     */
    public static function init(): void
    {
        if (static::$objectStorage === null) {
            // 根据当前环境选择适当的数据结构
            static::$objectStorage = class_exists(WeakMap::class) ? new WeakMap() : new SplObjectStorage();
            static::$object = new StdClass();
        }
    }

    /**
     * 获取当前上下文对象，如果没有，则创建一个新的 StdClass 对象
     *
     * @return StdClass
     */
    protected static function getObject(): StdClass
    {
        // 获取当前的上下文 key
        $key = static::getKey();

        // 如果当前上下文对象没有存储在 $objectStorage 中，则初始化一个新的对象
        if (!isset(static::$objectStorage[$key])) {
            static::$objectStorage[$key] = new StdClass();
        }

        return static::$objectStorage[$key];
    }

    /**
     * 根据当前的事件循环环境返回一个唯一的 key，作为上下文对象的标识
     * 这个 key 可以是 Fiber、Coroutine 或其他类型的唯一标识符
     *
     * @return mixed
     */
    protected static function getKey(): mixed
    {
        // 根据当前的事件循环类来确定使用的上下文 key
        return match (Worker::$eventLoopClass) {
            Revolt::class => Fiber::getCurrent(),   // 使用 Fiber 作为 key
            Swoole::class => \Swoole\Coroutine::getContext(),  // 使用 Swoole 协程上下文作为 key
            Swow::class   => Coroutine::getCurrent(),  // 使用 Swow 协程上下文作为 key
            default       => static::$object,  // 默认使用 static::$object 作为 key
        };
    }

    /**
     * 获取指定 key 的值，如果 key 为 null，则返回整个上下文对象
     * 如果指定的 key 不存在，则返回 null
     *
     * @param string|null $key 上下文数据的键
     * @return mixed
     */
    public static function get(?string $key = null): mixed
    {
        // 获取当前的上下文对象
        $obj = static::getObject();

        // 如果没有指定 key，则返回整个对象
        if ($key === null) {
            return $obj;
        }

        // 返回指定 key 的值，如果不存在则返回 null
        return $obj->$key ?? null;
    }

    /**
     * 设置指定 key 的值
     *
     * @param string $key 上下文数据的键
     * @param $value 设置的值
     * @return void
     */
    public static function set(string $key, $value): void
    {
        // 获取当前的上下文对象
        $obj = static::getObject();

        // 设置指定 key 的值
        $obj->$key = $value;
    }

    /**
     * 删除指定 key 的值
     *
     * @param string $key 要删除的上下文数据键
     * @return void
     */
    public static function delete(string $key): void
    {
        // 获取当前的上下文对象
        $obj = static::getObject();

        // 删除指定的键值对
        unset($obj->$key);
    }

    /**
     * 检查指定的 key 是否存在于上下文中
     *
     * @param string $key 上下文数据的键
     * @return bool 如果指定的 key 存在，则返回 true，否则返回 false
     */
    public static function has(string $key): bool
    {
        // 获取当前的上下文对象
        $obj = static::getObject();

        // 检查指定的 key 是否存在
        return property_exists($obj, $key);
    }

    /**
     * 销毁当前上下文，删除当前上下文对象
     *
     * @return void
     */
    public static function destroy(): void
    {
        // 删除当前上下文对象的存储
        unset(static::$objectStorage[static::getKey()]);
    }
}
