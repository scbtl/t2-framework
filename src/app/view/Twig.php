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

namespace app\view;

use RuntimeException;
use Twig\Environment;
use Twig\Error\LoaderError;
use Twig\Error\RuntimeError;
use Twig\Error\SyntaxError;
use Twig\Loader\FilesystemLoader;
use t2\View;
use function app_path;
use function array_merge;
use function base_path;
use function config;
use function is_array;
use function request;

class Twig implements View
{
    /**
     * Assign variables to the view.
     *
     * @param string|array $name Variable name or an associative array of variables.
     * @param mixed|null $value Value of the variable (ignored if $name is an array).
     * @return void
     * @throws RuntimeException If the request object is not available.
     */
    public static function assign(string|array $name, mixed $value = null): void
    {
        // Get the current request object
        $request = request();

        // Check if the request object is valid
        if (!$request) {
            throw new RuntimeException('Request object not found.');
        }

        // Ensure _view_vars is initialized as an array
        if (!isset($request->_view_vars)) {
            $request->_view_vars = [];
        }

        // Merge new variables into the _view_vars array
        $variables = is_array($name) ? $name : [$name => $value];
        $request->_view_vars = array_merge($request->_view_vars, $variables);
    }

    /**
     * Render a template file with variables.
     *
     * @param string $template 模板文件路径
     * @param array $vars 渲染时的变量
     * @param string|null $app 应用标识，可选
     * @return string 渲染后的内容
     * @throws LoaderError 如果加载模板文件失败
     * @throws RuntimeError 如果运行时错误
     * @throws SyntaxError 如果模板语法错误
     */
    public static function render(string $template, array $vars, ?string $app = null): string
    {
        static $views = []; // 用于缓存不同路径的视图环境实例
        $request = request(); // 获取当前请求对象

        // 如果未指定应用标识，则从请求对象中获取，默认为空字符串
        $app = $app ?? ($request->app ?? '');

        // 获取视图模板文件的后缀配置，默认为 "html"
        $configPrefix = 'view.options.view_suffix';
        $viewSuffix = config($configPrefix, 'html');

        // 获取基础视图路径，默认为应用目录路径
        $baseViewPath = app_path();
        if ($template[0] === '/') {
            // 如果模板路径以斜杠开头，则认为是从项目根目录开始
            $viewPath = base_path(); // 项目根目录
            $template = substr($template, 1); // 移除路径开头的斜杠
        } else {
            // 根据是否有应用标识，构造视图路径
            $viewPath = $app === '' ? "$baseViewPath/view/" : "$baseViewPath/$app/view/";
        }

        // 如果缓存中没有该视图路径对应的环境实例，则创建新的实例
        if (!isset($views[$viewPath])) {
            $views[$viewPath] = new Environment(
                new FilesystemLoader($viewPath), // 设置视图加载器，指定视图路径
                config("{$configPrefix}view.options", []) // 获取视图配置选项
            );

            // 获取可选的扩展配置，并对视图实例进行扩展
            $extension = config("{$configPrefix}view.extension");
            if (is_callable($extension)) {
                $extension($views[$viewPath]);
            }
        }

        // 合并全局视图变量和当前渲染变量
        if (!empty($request->_view_vars)) {
            $vars = array_merge($request->_view_vars, $vars);
        }

        // 渲染模板并返回结果
        return $views[$viewPath]->render("$template.$viewSuffix", $vars);
    }
}