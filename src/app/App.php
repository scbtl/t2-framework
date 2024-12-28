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

use RuntimeException;
use t2\Config;
use t2\Util;
use Throwable;
use Workerman\Connection\TcpConnection;
use Workerman\Worker;
use function base_path;
use function call_user_func;
use function is_dir;
use function opcache_get_status;
use function opcache_invalidate;
use const DIRECTORY_SEPARATOR;

class App
{
    /**
     * Run
     * @return void
     */
    public static function run(): void
    {
        // 启用错误显示并设置为报告所有错误
        ini_set('display_errors', 'on');
        error_reporting(E_ALL);

        // 加载环境变量
        self::loadEnv();

        if (DIRECTORY_SEPARATOR === '\\' && empty(config('server.listen'))) {
            echo "Please run 'php windows.php' on windows system." . PHP_EOL;
            exit;
        }

        // 设置默认时区
        self::configureTimezone();

        static::loadAllConfig(['route', 'container']);

        // 配置错误报告级别
        self::configureErrorReporting();

        // 确保日志和视图目录存在
        self::ensureDirectoryExists(runtime_path() . DIRECTORY_SEPARATOR . 'logs', 'runtime logs');
        self::ensureDirectoryExists(runtime_path() . DIRECTORY_SEPARATOR . 'views', 'runtime views');

        Worker::$onMasterReload = function () {
            if (function_exists('opcache_get_status')) {
                if ($status = opcache_get_status()) {
                    if (isset($status['scripts']) && $scripts = $status['scripts']) {
                        foreach (array_keys($scripts) as $file) {
                            opcache_invalidate($file, true);
                        }
                    }
                }
            }
        };

        $config = config('server');
        Worker::$pidFile = $config['pid_file'];
        Worker::$stdoutFile = $config['stdout_file'];
        Worker::$logFile = $config['log_file'];
        Worker::$eventLoopClass = $config['event_loop'] ?? '';
        TcpConnection::$defaultMaxPackageSize = $config['max_package_size'] ?? 10 * 1024 * 1024;
        if (property_exists(Worker::class, 'statusFile')) {
            Worker::$statusFile = $config['status_file'] ?? '';
        }

        if (property_exists(Worker::class, 'stopTimeout')) {
            Worker::$stopTimeout = $config['stop_timeout'] ?? 2;
        }

        if ($config['listen'] ?? false) {
            $worker = new Worker($config['listen'], $config['context']);
            $propertyMap = [
                'name',
                'count',
                'user',
                'group',
                'reusePort',
                'transport',
                'protocol'
            ];

            foreach ($propertyMap as $property) {
                if (isset($config[$property])) {
                    $worker->$property = $config[$property];
                }
            }

            $worker->onWorkerStart = function ($worker) {
                require_once base_path() . '/app/bootstrap.php';
                $app = new \t2\App(config('app.request_class', Request::class), Log::channel(), app_path(), public_path());
                $worker->onMessage = [$app, 'onMessage'];
                call_user_func([$app, 'onWorkerStart'], $worker);
            };
        }

        // Windows does not app custom processes.
        if (DIRECTORY_SEPARATOR === '/') {
            foreach (config('process', []) as $processName => $config) {
                worker_start($processName, $config);
            }
            foreach (config('plugin', []) as $firm => $projects) {
                foreach ($projects as $name => $project) {
                    if (!is_array($project)) {
                        continue;
                    }
                    foreach ($project['process'] ?? [] as $processName => $config) {
                        worker_start("plugin.$firm.$name.$processName", $config);
                    }
                }
                foreach ($projects['process'] ?? [] as $processName => $config) {
                    worker_start("plugin.$firm.$processName", $config);
                }
            }
        }

        Worker::runAll();
    }

    /**
     * 加载环境变量
     * @return void
     */
    private static function loadEnv(): void
    {
        // 检查 Env 类是否存在以及其是否具有 load 方法
        if (!class_exists(Env::class) || !method_exists(Env::class, 'load')) {
            return;
        }

        // 确定 .env 文件路径
        $envFilePath = base_path('.env');
        if (!file_exists($envFilePath)) {
            // 可以选择记录日志，说明 .env 文件不存在
            error_log("Environment file not found at: $envFilePath");
            return;
        }

        // 加载 .env 文件并处理可能的异常
        try {
            Env::load($envFilePath);
        } catch (Throwable $e) {
            // 记录日志或处理加载失败
            error_log("Failed to load environment file: " . $e->getMessage());
        }
    }

    /**
     * 设置默认时区
     * @return void
     */
    private static function configureTimezone(): void
    {
        date_default_timezone_set(config('app.default_timezone') ?? date_default_timezone_get());
    }

    /**
     * 配置错误报告级别
     * @return void
     */
    private static function configureErrorReporting(): void
    {
        error_reporting(config('app.error_reporting') ?? error_reporting());
    }

    /**
     * 日志保存目录，如果目录不存在则创建
     * @param string $path
     * @param string $name
     * @return void
     */
    private static function ensureDirectoryExists(string $path, string $name): void
    {
        if (!is_dir($path) && !@mkdir($path, 0777, true)) {
            throw new RuntimeException("Failed to create $name directory. Please check permissions.");
        }
    }

    /**
     * LoadAllConfig
     * @param array $excludes
     * @return void
     */
    public static function loadAllConfig(array $excludes = []): void
    {
        Config::load(config_path(), $excludes);
        $directory = base_path() . '/plugin';
        foreach (Util::scanDir($directory, false) as $name) {
            $dir = "$directory/$name/config";
            if (is_dir($dir)) {
                Config::load($dir, $excludes, "plugin.$name");
            }
        }
    }
}