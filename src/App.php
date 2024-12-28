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

use Closure;
use Exception;
use FastRoute\Dispatcher;
use Illuminate\Database\Eloquent\Model;
use ReflectionEnum;
use app\exception\InputValueException;
use app\exception\PageNotFoundException;
use InvalidArgumentException;
use Monolog\Logger;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use ReflectionClass;
use ReflectionException;
use ReflectionFunction;
use ReflectionFunctionAbstract;
use ReflectionMethod;
use app\exception\MissingInputException;
use app\exception\InputTypeException;
use StdClass;
use Throwable;
use t2\exception\ExceptionHandler;
use t2\exception\ExceptionHandlerInterface;
use t2\http\Request;
use t2\http\Response;
use t2\route\Route as RouteObject;
use Workerman\Connection\TcpConnection;
use Workerman\Protocols\Http;
use Workerman\Worker;
use function array_merge;
use function array_pop;
use function array_reduce;
use function array_splice;
use function array_values;
use function class_exists;
use function clearstatcache;
use function count;
use function current;
use function end;
use function explode;
use function get_class_methods;
use function gettype;
use function implode;
use function is_a;
use function is_array;
use function is_dir;
use function is_file;
use function is_numeric;
use function is_string;
use function key;
use function method_exists;
use function ob_get_clean;
use function ob_start;
use function pathinfo;
use function scandir;
use function str_replace;
use function strtolower;
use function substr;
use function trim;

class App
{
    /**
     * @var callable[]
     */
    protected static array $callbacks = [];

    /**
     * @var Worker|null
     */
    protected static ?Worker $worker = null;

    /**
     * @var Logger|null
     */
    protected static ?Logger $logger = null;

    /**
     * @var string
     */
    protected static string $appPath = '';

    /**
     * @var string
     */
    protected static string $publicPath = '';

    /**
     * @var string
     */
    protected static string $requestClass = '';

    /**
     * App constructor.
     * @param string $requestClass
     * @param Logger $logger
     * @param string $appPath
     * @param string $publicPath
     */
    public function __construct(string $requestClass, Logger $logger, string $appPath, string $publicPath)
    {
        static::$requestClass = $requestClass;
        static::$logger = $logger;
        static::$publicPath = $publicPath;
        static::$appPath = $appPath;
    }

    /**
     * OnMessage.
     * @param $connection
     * @param $request
     * @return null
     */
    public function onMessage($connection, $request): null
    {
        try {
            Context::set(Request::class, $request);
            $path = $request->path();
            $key = $request->method() . $path;
            if (isset(static::$callbacks[$key])) {
                [$callback, $request->plugin, $request->app, $request->controller, $request->action, $request->route] = static::$callbacks[$key];
                static::send($connection, $callback($request), $request);

                return null;
            }

            $status = 200;
            if (static::unsafeUri($connection, $path, $request) || static::findFile($connection, $path, $key, $request) || static::findRoute($connection, $path, $key, $request, $status)) {
                return null;
            }

            $controllerAndAction = static::parseControllerAction($path);
            $plugin = $controllerAndAction['plugin'] ?? static::getPluginByPath($path);
            if (!$controllerAndAction || Route::isDefaultRouteDisabled($plugin, $controllerAndAction['app'] ?: '*') ||
                Route::isDefaultRouteDisabled($controllerAndAction['controller']) ||
                Route::isDefaultRouteDisabled([$controllerAndAction['controller'], $controllerAndAction['action']])) {
                $request->plugin = $plugin;
                $callback = static::getFallback($plugin, $status);
                $request->app = $request->controller = $request->action = '';
                static::send($connection, $callback($request), $request);

                return null;
            }

            $app = $controllerAndAction['app'];
            $controller = $controllerAndAction['controller'];
            $action = $controllerAndAction['action'];
            $callback = static::getCallback($plugin, $app, [$controller, $action]);
            static::collectCallbacks($key, [$callback, $plugin, $app, $controller, $action, null]);
            [$callback, $request->plugin, $request->app, $request->controller, $request->action, $request->route] = static::$callbacks[$key];
            static::send($connection, $callback($request), $request);
        } catch (Throwable $e) {
            static::send($connection, static::exceptionResponse($e, $request), $request);
        }

        return null;
    }

    /**
     * OnWorkerStart
     * @param $worker
     * @return void
     */
    public function onWorkerStart($worker): void
    {
        static::$worker = $worker;
        Http::requestClass(static::$requestClass);
        Context::init();
    }

    /**
     * CollectCallbacks.
     * @param string $key
     * @param array $data
     * @return void
     */
    protected static function collectCallbacks(string $key, array $data): void
    {
        static::$callbacks[$key] = $data;
        if (count(static::$callbacks) >= 1024) {
            unset(static::$callbacks[key(static::$callbacks)]);
        }
    }

    /**
     * UnsafeUri
     * @param TcpConnection $connection
     * @param string $path
     * @param $request
     * @return bool
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     */
    protected static function unsafeUri(TcpConnection $connection, string $path, $request): bool
    {
        if (!$path || $path[0] !== '/' || str_contains($path, '/../') || str_ends_with($path, '/..') || str_contains($path, "\\") || str_contains($path, "\0")) {
            $callback = static::getFallback('', 400);
            $request->plugin = $request->app = $request->controller = $request->action = '';
            static::send($connection, $callback($request, 400), $request);

            return true;
        }

        return false;
    }

    /**
     * GetFallback.
     * @param string $plugin
     * @param int $status
     * @return Closure
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     */
    protected static function getFallback(string $plugin = '', int $status = 404): Closure
    {
        // When route, controller and action not found, try to use route::fallback
        return Route::getFallback($plugin, $status) ?: function () {
            throw new PageNotFoundException();
        };
    }

    /**
     * ExceptionResponse.
     * @param Throwable $e
     * @param $request
     * @return Response
     */
    protected static function exceptionResponse(Throwable $e, $request): Response
    {
        try {
            $app = $request->app ?: '';
            $plugin = $request->plugin ?: '';
            $exceptionConfig = static::config($plugin, 'exception');
            $appExceptionConfig = static::config("", 'exception');

            // 检查插件是否配置了异常处理器并且全局是否配置了异常处理器
            $defaultException = ExceptionHandler::class;
            if (isset($appExceptionConfig['@'])) {
                // 如果插件没有配置自己的异常处理器且全局配置了异常处理器，则使用全局异常处理器
                $defaultException = $appExceptionConfig['@'];
            }

            $exceptionHandlerClass = $exceptionConfig[$app] ?? $defaultException;

            /** @var ExceptionHandlerInterface $exceptionHandler */
            $exceptionHandler = (static::container($plugin) ?? static::container())->make($exceptionHandlerClass, [
                'logger' => static::$logger,
                'debug'  => static::config($plugin, 'app.debug')
            ]);
            $exceptionHandler->report($e);
            $response = $exceptionHandler->render($request, $e);
            $response->exception($e);

            return $response;
        } catch (Throwable $e) {
            $response = new Response(500, [], static::config($plugin ?? '', 'app.debug', true) ? (string)$e : $e->getMessage());
            $response->exception($e);

            return $response;
        }
    }

    /**
     * GetCallback
     * @param string $plugin
     * @param string $app
     * @param $call
     * @param array $args
     * @param bool $withGlobalMiddleware
     * @param RouteObject|null $route
     * @return Closure|mixed
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     */
    public static function getCallback(string $plugin, string $app, $call, array $args = [], bool $withGlobalMiddleware = true, ?RouteObject $route = null): mixed
    {
        $middlewares = [];
        if ($route) {
            $routeMiddlewares = $route->getMiddleware();
            foreach ($routeMiddlewares as $className) {
                $middlewares[] = [$className, 'process'];
            }
        }

        $isController = is_array($call) && is_string($call[0]);
        $middlewares = array_merge($middlewares, Middleware::getMiddleware($plugin, $app, $isController ? $call[0] : '', $withGlobalMiddleware));

        $container = static::container($plugin) ?? static::container();
        foreach ($middlewares as $key => $item) {
            $middleware = $item[0];
            if (is_string($middleware)) {
                $middleware = $container->get($middleware);
            } elseif ($middleware instanceof Closure) {
                $middleware = call_user_func($middleware, $container);
            }

            if (!$middleware instanceof MiddlewareInterface) {
                throw new InvalidArgumentException('Not support middleware type');
            }

            $middlewares[$key][0] = $middleware;
        }

        $needInject = static::isNeedInject($call, $args);
        $anonymousArgs = array_values($args);
        if ($isController) {
            $controllerReuse = static::config($plugin, 'app.controller_reuse', true);
            if (!$controllerReuse) {
                if ($needInject) {
                    $call = function ($request) use ($call, $plugin, $args, $container) {
                        $call[0] = $container->make($call[0]);
                        $reflector = static::getReflector($call);
                        $args = array_values(static::resolveMethodDependencies($container, $request, array_merge($request->all(), $args), $reflector, static::config($plugin, 'app.debug')));
                        return $call(...$args);
                    };
                    $needInject = false;
                } else {
                    $call = function ($request, ...$anonymousArgs) use ($call, $plugin, $container) {
                        $call[0] = $container->make($call[0]);
                        return $call($request, ...$anonymousArgs);
                    };
                }
            } else {
                $call[0] = $container->get($call[0]);
            }
        }

        if ($needInject) {
            $call = static::resolveInject($plugin, $call, $args);
        }

        if ($middlewares) {
            $callback = array_reduce($middlewares, function ($carry, $pipe) {
                return function ($request) use ($carry, $pipe) {
                    try {
                        return $pipe($request, $carry);
                    } catch (Throwable $e) {
                        return static::exceptionResponse($e, $request);
                    }
                };
            }, function ($request) use ($call, $anonymousArgs) {
                try {
                    $response = $call($request, ...$anonymousArgs);
                } catch (Throwable $e) {
                    return static::exceptionResponse($e, $request);
                }
                if (!$response instanceof Response) {
                    if (!is_string($response)) {
                        $response = static::stringify($response);
                    }
                    $response = new Response(200, [], $response);
                }

                return $response;
            });
        } else {
            if (!$anonymousArgs) {
                $callback = $call;
            } else {
                $callback = function ($request) use ($call, $anonymousArgs) {
                    return $call($request, ...$anonymousArgs);
                };
            }
        }

        return $callback;
    }

    /**
     * ResolveInject.
     * @param string $plugin
     * @param array|Closure $call
     * @param $args
     * @return Closure
     * @see Dependency injection through reflection information
     */
    protected static function resolveInject(string $plugin, array|Closure $call, $args): Closure
    {
        return function (Request $request) use ($plugin, $call, $args) {
            $reflector = static::getReflector($call);
            $args = array_values(static::resolveMethodDependencies(static::container($plugin), $request, array_merge($request->all(), $args), $reflector, static::config($plugin, 'app.debug')));

            return $call(...$args);
        };
    }

    /**
     * Check whether inject is required.
     * @param $call
     * @param array $args
     * @return bool
     * @throws ReflectionException
     */
    protected static function isNeedInject($call, array &$args): bool
    {
        if (is_array($call) && !method_exists($call[0], $call[1])) {
            return false;
        }

        $reflector = static::getReflector($call);
        $reflectionParameters = $reflector->getParameters();

        if (!$reflectionParameters) {
            return false;
        }

        $firstParameter = current($reflectionParameters);
        unset($reflectionParameters[key($reflectionParameters)]);
        $adaptersList = ['int', 'string', 'bool', 'array', 'object', 'float', 'mixed', 'resource'];
        $keys = [];
        $needInject = false;
        foreach ($reflectionParameters as $parameter) {
            $parameterName = $parameter->name;
            $keys[] = $parameterName;
            if ($parameter->hasType()) {
                $typeName = $parameter->getType()->getName();
                if (!in_array($typeName, $adaptersList)) {
                    $needInject = true;
                    continue;
                }

                if (!array_key_exists($parameterName, $args)) {
                    $needInject = true;
                    continue;
                }

                switch ($typeName) {
                    case 'int':
                    case 'float':
                        if (!is_numeric($args[$parameterName])) {
                            return true;
                        }

                        $args[$parameterName] = $typeName === 'int' ? (int)$args[$parameterName] : (float)$args[$parameterName];
                        break;
                    case 'bool':
                        $args[$parameterName] = (bool)$args[$parameterName];
                        break;
                    case 'array':
                    case 'object':
                        if (!is_array($args[$parameterName])) {
                            return true;
                        }

                        $args[$parameterName] = $typeName === 'array' ? $args[$parameterName] : (object)$args[$parameterName];
                        break;
                    case 'string':
                    case 'mixed':
                    case 'resource':
                        break;
                }
            }
        }

        if (array_keys($args) !== $keys) {
            return true;
        }

        if (!$firstParameter->hasType()) {
            return $firstParameter->getName() !== 'request';
        }

        if (!is_a(static::$requestClass, $firstParameter->getType()->getName(), true)) {
            return true;
        }

        return $needInject;
    }

    /**
     * Get reflector
     * @param $call
     * @return ReflectionFunction|ReflectionMethod
     * @throws ReflectionException
     */
    protected static function getReflector($call): ReflectionMethod|ReflectionFunction
    {
        if ($call instanceof Closure || is_string($call)) {
            return new ReflectionFunction($call);
        }

        return new ReflectionMethod($call[0], $call[1]);
    }

    /**
     * Return dependent parameters
     * @param ContainerInterface $container
     * @param Request $request
     * @param array $inputs
     * @param ReflectionFunctionAbstract $reflector
     * @param bool $debug
     * @return array
     * @throws ReflectionException
     */
    protected static function resolveMethodDependencies(ContainerInterface $container, Request $request, array $inputs, ReflectionFunctionAbstract $reflector, bool $debug): array
    {
        $parameters = [];
        foreach ($reflector->getParameters() as $parameter) {
            $parameterName = $parameter->name;
            $type = $parameter->getType();
            $typeName = $type?->getName();

            if ($typeName && is_a($request, $typeName)) {
                $parameters[$parameterName] = $request;
                continue;
            }

            if (!array_key_exists($parameterName, $inputs)) {
                if (!$parameter->isDefaultValueAvailable()) {
                    if (!$typeName || (!class_exists($typeName) && !enum_exists($typeName)) || enum_exists($typeName)) {
                        throw (new MissingInputException())->data([
                            'parameter' => $parameterName,
                        ])->debug($debug);
                    }
                } else {
                    $parameters[$parameterName] = $parameter->getDefaultValue();
                    continue;
                }
            }

            $parameterValue = $inputs[$parameterName] ?? null;

            switch ($typeName) {
                case 'int':
                case 'float':
                    if (!is_numeric($parameterValue)) {
                        throw (new InputTypeException())->data([
                            'parameter'  => $parameterName,
                            'exceptType' => $typeName,
                            'actualType' => gettype($parameterValue),
                        ])->debug($debug);
                    }
                    $parameters[$parameterName] = $typeName === 'float' ? (float)$parameterValue : (int)$parameterValue;
                    break;
                case 'bool':
                    $parameters[$parameterName] = (bool)$parameterValue;
                    break;
                case 'array':
                case 'object':
                    if (!is_array($parameterValue)) {
                        throw (new InputTypeException())->data([
                            'parameter'  => $parameterName,
                            'exceptType' => $typeName,
                            'actualType' => gettype($parameterValue),
                        ])->debug($debug);
                    }
                    $parameters[$parameterName] = $typeName === 'object' ? (object)$parameterValue : $parameterValue;
                    break;
                case 'string':
                case 'mixed':
                case 'resource':
                case null:
                    $parameters[$parameterName] = $parameterValue;
                    break;
                default:
                    $subInputs = is_array($parameterValue) ? $parameterValue : [];
                    if (is_a($typeName, Model::class, true)) {
                        $parameters[$parameterName] = $container->make($typeName, [
                            'attributes' => $subInputs,
                            'data'       => $subInputs
                        ]);
                        break;
                    }
                    if (enum_exists($typeName)) {
                        $reflection = new ReflectionEnum($typeName);
                        if ($reflection->hasCase($parameterValue)) {
                            $parameters[$parameterName] = $reflection->getCase($parameterValue)->getValue();
                            break;
                        } elseif ($reflection->isBacked()) {
                            foreach ($reflection->getCases() as $case) {
                                if ($case->getValue()->value == $parameterValue) {
                                    $parameters[$parameterName] = $case->getValue();
                                    break;
                                }
                            }
                        }
                        if (!array_key_exists($parameterName, $parameters)) {
                            throw (new InputValueException())->data([
                                'parameter' => $parameterName,
                                'enum'      => $typeName
                            ])->debug($debug);
                        }
                        break;
                    }
                    if (is_array($subInputs) && $constructor = (new ReflectionClass($typeName))->getConstructor()) {
                        $parameters[$parameterName] = $container->make($typeName, static::resolveMethodDependencies($container, $request, $subInputs, $constructor, $debug));
                    } else {
                        $parameters[$parameterName] = $container->make($typeName);
                    }
                    break;
            }
        }

        return $parameters;
    }

    /**
     * Container
     * @param string $plugin
     * @return mixed
     */
    public static function container(string $plugin = ''): mixed
    {
        return static::config($plugin, 'container');
    }

//    /**
//     * Get request.
//     * @return Request|\App\Request
//     */
    /**
     * Get request
     * @return mixed|StdClass|null
     */
    public static function request(): mixed
    {
        return Context::get(Request::class);
    }

    /**
     * Get worker
     * @return Worker|null
     */
    public static function worker(): ?Worker
    {
        return static::$worker;
    }

    /**
     * Find route.
     * @param TcpConnection $connection
     * @param string $path
     * @param string $key
     * @param $request
     * @param $status
     * @return bool
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException|Throwable
     */
    protected static function findRoute(TcpConnection $connection, string $path, string $key, $request, &$status): bool
    {
        $routeInfo = Route::dispatch($request->method(), $path);
        if ($routeInfo[0] === Dispatcher::FOUND) {
            $status = 200;
            $routeInfo[0] = 'route';
            $callback = $routeInfo[1]['callback'];
            $route = clone $routeInfo[1]['route'];
            $app = $controller = $action = '';
            $args = !empty($routeInfo[2]) ? $routeInfo[2] : [];
            if ($args) {
                $route->setParams($args);
            }

            if (is_array($callback)) {
                $controller = $callback[0];
                $plugin = static::getPluginByClass($controller);
                $app = static::getAppByController($controller);
                $action = static::getRealMethod($controller, $callback[1]) ?? '';
            } else {
                $plugin = static::getPluginByPath($path);
            }

            $callback = static::getCallback($plugin, $app, $callback, $args, true, $route);
            static::collectCallbacks($key, [$callback, $plugin, $app, $controller ?: '', $action, $route]);
            [$callback, $request->plugin, $request->app, $request->controller, $request->action, $request->route] = static::$callbacks[$key];
            static::send($connection, $callback($request), $request);

            return true;
        }
        $status = $routeInfo[0] === Dispatcher::METHOD_NOT_ALLOWED ? 405 : 404;

        return false;
    }

    /**
     * Find File.
     * @param TcpConnection $connection
     * @param string $path
     * @param string $key
     * @param $request
     * @return bool
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     */
    protected static function findFile(TcpConnection $connection, string $path, string $key, $request): bool
    {
        if (preg_match('/%[0-9a-f]{2}/i', $path)) {
            $path = urldecode($path);
            if (static::unsafeUri($connection, $path, $request)) {
                return true;
            }
        }

        $pathExplodes = explode('/', trim($path, '/'));
        $plugin = '';
        if (isset($pathExplodes[1]) && $pathExplodes[0] === 'app') {
            $plugin = $pathExplodes[1];
            $publicDir = static::config($plugin, 'app.public_path') ?: BASE_PATH . "/plugin/$pathExplodes[1]/public";
            $path = substr($path, strlen("/app/$pathExplodes[1]/"));
        } else {
            $publicDir = static::$publicPath;
        }

        $file = "$publicDir/$path";
        if (!is_file($file)) {
            return false;
        }

        if (pathinfo($file, PATHINFO_EXTENSION) === 'php') {
            if (!static::config($plugin, 'app.support_php_files', false)) {
                return false;
            }

            static::collectCallbacks($key, [function () use ($file) {
                return static::execPhpFile($file);
            }, '', '', '', '', null]);
            [, $request->plugin, $request->app, $request->controller, $request->action, $request->route] = static::$callbacks[$key];
            static::send($connection, static::execPhpFile($file), $request);

            return true;
        }

        if (!static::config($plugin, 'static.enable', false)) {
            return false;
        }

        static::collectCallbacks($key, [static::getCallback($plugin, '__static__', function ($request) use ($file, $plugin) {
            clearstatcache(true, $file);
            if (!is_file($file)) {
                $callback = static::getFallback($plugin);
                return $callback($request);
            }
            return (new Response())->file($file);
        }, [], false), '', '', '', '', null]);
        [$callback, $request->plugin, $request->app, $request->controller, $request->action, $request->route] = static::$callbacks[$key];
        static::send($connection, $callback($request), $request);

        return true;
    }

    /**
     * Send
     * @param $connection
     * @param $response
     * @param $request
     * @return void
     */
    protected static function send($connection, $response, $request): void
    {
        $keepAlive = $request->header('connection');
        Context::destroy();
        if (($keepAlive === null && $request->protocolVersion() === '1.1') || $keepAlive === 'keep-alive' || $keepAlive === 'Keep-Alive' || (is_a($response, Response::class) && $response->getHeader('Transfer-Encoding') === 'chunked')) {
            $connection->send($response);
            return;
        }

        $connection->close($response);
    }

    /**
     * ParseControllerAction
     * @param string $path
     * @return array|false|mixed
     * @throws ReflectionException
     */
    protected static function parseControllerAction(string $path): mixed
    {
        $path = str_replace(['-', '//'], ['', '/'], $path);
        static $cache = [];
        if (isset($cache[$path])) {
            return $cache[$path];
        }

        $pathExplode = explode('/', trim($path, '/'));
        $isPlugin = isset($pathExplode[1]) && $pathExplode[0] === 'app';
        $configPrefix = $isPlugin ? "plugin.$pathExplode[1]." : '';
        $pathPrefix = $isPlugin ? "/app/$pathExplode[1]" : '';
        $classPrefix = $isPlugin ? "plugin\\$pathExplode[1]" : '';
        $suffix = Config::get("{$configPrefix}app.controller_suffix", '');
        $relativePath = trim(substr($path, strlen($pathPrefix)), '/');
        $pathExplode = $relativePath ? explode('/', $relativePath) : [];

        $action = 'index';
        if (!$controllerAction = static::guessControllerAction($pathExplode, $action, $suffix, $classPrefix)) {
            if (count($pathExplode) <= 1) {
                return false;
            }
            $action = end($pathExplode);
            unset($pathExplode[count($pathExplode) - 1]);
            $controllerAction = static::guessControllerAction($pathExplode, $action, $suffix, $classPrefix);
        }

        if ($controllerAction && !isset($path[256])) {
            $cache[$path] = $controllerAction;
            if (count($cache) > 1024) {
                unset($cache[key($cache)]);
            }
        }

        return $controllerAction;
    }

    /**
     * GuessControllerAction
     * @param $pathExplode
     * @param $action
     * @param $suffix
     * @param $classPrefix
     * @return array|false
     * @throws ReflectionException
     */
    protected static function guessControllerAction($pathExplode, $action, $suffix, $classPrefix): false|array
    {
        $map[] = trim("$classPrefix\\app\\controller\\" . implode('\\', $pathExplode), '\\');
        foreach ($pathExplode as $index => $section) {
            $tmp = $pathExplode;
            array_splice($tmp, $index, 1, [$section, 'controller']);
            $map[] = trim("$classPrefix\\" . implode('\\', array_merge(['app'], $tmp)), '\\');
        }

        foreach ($map as $item) {
            $map[] = $item . '\\index';
        }

        foreach ($map as $controllerClass) {
            // Remove xx\xx\controller
            if (str_ends_with($controllerClass, '\\controller')) {
                continue;
            }
            $controllerClass .= $suffix;
            if ($controllerAction = static::getControllerAction($controllerClass, $action)) {
                return $controllerAction;
            }
        }

        return false;
    }

    /**
     * GetControllerAction
     * @param string $controllerClass
     * @param string $action
     * @return array|false
     * @throws ReflectionException
     */
    protected static function getControllerAction(string $controllerClass, string $action): false|array
    {
        // Disable calling magic methods
        if (str_starts_with($action, '__')) {
            return false;
        }

        if (($controllerClass = static::getController($controllerClass)) && ($action = static::getAction($controllerClass, $action))) {
            return [
                'plugin'     => static::getPluginByClass($controllerClass),
                'app'        => static::getAppByController($controllerClass),
                'controller' => $controllerClass,
                'action'     => $action
            ];
        }

        return false;
    }

    /**
     * GetController
     * @param string $controllerClass
     * @return false|string
     * @throws ReflectionException
     */
    protected static function getController(string $controllerClass): false|string
    {
        if (class_exists($controllerClass)) {
            return (new ReflectionClass($controllerClass))->name;
        }

        $explodes = explode('\\', strtolower(ltrim($controllerClass, '\\')));
        $basePath = $explodes[0] === 'plugin' ? BASE_PATH . '/plugin' : static::$appPath;
        unset($explodes[0]);
        $fileName = array_pop($explodes) . '.php';
        $found = true;
        foreach ($explodes as $pathSection) {
            if (!$found) {
                break;
            }

            $dirs = Util::scanDir($basePath, false);
            $found = false;
            foreach ($dirs as $name) {
                $path = "$basePath/$name";

                if (is_dir($path) && strtolower($name) === $pathSection) {
                    $basePath = $path;
                    $found = true;
                    break;
                }
            }
        }

        if (!$found) {
            return false;
        }

        foreach (scandir($basePath) ?: [] as $name) {
            if (strtolower($name) === $fileName) {
                require_once "$basePath/$name";
                if (class_exists($controllerClass, false)) {
                    return (new ReflectionClass($controllerClass))->name;
                }
            }
        }

        return false;
    }

    /**
     * GetAction
     * @param string $controllerClass
     * @param string $action
     * @return false|string
     */
    protected static function getAction(string $controllerClass, string $action): false|string
    {
        $methods = get_class_methods($controllerClass);
        $lowerAction = strtolower($action);
        $found = false;
        foreach ($methods as $candidate) {
            if (strtolower($candidate) === $lowerAction) {
                $action = $candidate;
                $found = true;
                break;
            }
        }

        if ($found) {
            return $action;
        }

        // Action is not public method
        if (method_exists($controllerClass, $action)) {
            return false;
        }

        if (method_exists($controllerClass, '__call')) {
            return $action;
        }

        return false;
    }

    /**
     * GetPluginByClass
     * @param string $controllerClass
     * @return string
     */
    public static function getPluginByClass(string $controllerClass): string
    {
        $controllerClass = trim($controllerClass, '\\');
        $tmp = explode('\\', $controllerClass, 3);
        if ($tmp[0] !== 'plugin') {
            return '';
        }

        return $tmp[1] ?? '';
    }

    /**
     * GetPluginByPath
     * @param string $path
     * @return string
     */
    public static function getPluginByPath(string $path): string
    {
        $path = trim($path, '/');
        $tmp = explode('/', $path, 3);
        if ($tmp[0] !== 'app') {
            return '';
        }

        $plugin = $tmp[1] ?? '';
        if ($plugin && !static::config('', "plugin.$plugin.app")) {
            return '';
        }

        return $plugin;
    }

    /**
     * GetAppByController
     * @param string $controllerClass
     * @return mixed|string
     */
    protected static function getAppByController(string $controllerClass): mixed
    {
        $controllerClass = trim($controllerClass, '\\');
        $tmp = explode('\\', $controllerClass, 5);
        $pos = $tmp[0] === 'plugin' ? 3 : 1;
        if (!isset($tmp[$pos])) {
            return '';
        }

        return strtolower($tmp[$pos]) === 'controller' ? '' : $tmp[$pos];
    }

    /**
     * ExecPhpFile
     * @param string $file
     * @return false|string
     */
    public static function execPhpFile(string $file): false|string
    {
        ob_start();
        // Try to include php file.
        try {
            include $file;
        } catch (Exception $e) {
            echo $e;
        }

        return ob_get_clean();
    }

    /**
     * GetRealMethod.
     * @param string $class
     * @param string $method
     * @return string
     */
    protected static function getRealMethod(string $class, string $method): string
    {
        $method = strtolower($method);
        $methods = get_class_methods($class);
        foreach ($methods as $candidate) {
            if (strtolower($candidate) === $method) {
                return $candidate;
            }
        }

        return $method;
    }

    /**
     * Config
     * @param string $plugin
     * @param string $key
     * @param mixed|null $default
     * @return array|mixed
     */
    protected static function config(string $plugin, string $key, mixed $default = null): mixed
    {
        return Config::get($plugin ? "plugin.$plugin.$key" : $key, $default);
    }

    /**
     * @param $data
     * @return string
     */
    protected static function stringify($data): string
    {
        $type = gettype($data);
        return match ($type) {
            'boolean' => $data ? 'true' : 'false',
            'NULL'    => 'NULL',
            'array'   => 'Array',
            'object'  => method_exists($data, '__toString') ? (string)$data : 'Object',
            default   => (string)$data,
        };
    }
}