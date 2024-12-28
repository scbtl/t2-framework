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
     * Render
     * @param string $template
     * @param array $vars
     * @param string|null $app
     * @param string|null $plugin
     * @return string
     * @throws LoaderError
     * @throws RuntimeError
     * @throws SyntaxError
     */
    public static function render(string $template, array $vars, ?string $app = null, ?string $plugin = null): string
    {
        static $views = [];
        $request = request();
        $plugin = $plugin === null ? ($request->plugin ?? '') : $plugin;
        $app = $app === null ? ($request->app ?? '') : $app;
        $configPrefix = $plugin ? "plugin.$plugin." : '';
        $viewSuffix = config("{$configPrefix}view.options.view_suffix", 'html');
        $baseViewPath = $plugin ? base_path() . "/plugin/$plugin/app" : app_path();

        if ($template[0] === '/') {
            $viewPath = base_path();
            $template = substr($template, 1);
        } else {
            $viewPath = $app === '' ? "$baseViewPath/view/" : "$baseViewPath/$app/view/";
        }

        if (!isset($views[$viewPath])) {
            $views[$viewPath] = new Environment(new FilesystemLoader($viewPath), config("{$configPrefix}view.options", []));
            $extension = config("{$configPrefix}view.extension");
            if ($extension) {
                $extension($views[$viewPath]);
            }
        }

        if (isset($request->_view_vars)) {
            $vars = array_merge((array)$request->_view_vars, $vars);
        }

        return $views[$viewPath]->render("$template.$viewSuffix", $vars);
    }
}