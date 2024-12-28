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

use Psr\Container\ContainerInterface;
use t2\exception\NotFoundException;
use function array_key_exists;
use function class_exists;

class Container implements ContainerInterface
{
    /**
     * @var array
     */
    protected $instances = [];
    /**
     * @var array
     */
    protected $definitions = [];

    /**
     * Get.
     * @param string $name
     * @return mixed
     * @throws NotFoundException
     */
    public function get(string $name)
    {
        if (!isset($this->instances[$name])) {
            if (isset($this->definitions[$name])) {
                $this->instances[$name] = call_user_func($this->definitions[$name], $this);
            } else {
                if (!class_exists($name)) {
                    throw new NotFoundException("Class '$name' not found");
                }
                $this->instances[$name] = new $name();
            }
        }

        return $this->instances[$name];
    }

    /**
     * Has.
     * @param string $name
     * @return bool
     */
    public function has(string $name): bool
    {
        return array_key_exists($name, $this->instances) || array_key_exists($name, $this->definitions);
    }

    /**
     * Make.
     * @param string $name
     * @param array $constructor
     * @return mixed
     * @throws NotFoundException
     */
    public function make(string $name, array $constructor = [])
    {
        if (!class_exists($name)) {
            throw new NotFoundException("Class '$name' not found");
        }

        return new $name(... array_values($constructor));
    }

    /**
     * AddDefinitions.
     * @param array $definitions
     * @return $this
     */
    public function addDefinitions(array $definitions): Container
    {
        $this->definitions = array_merge($this->definitions, $definitions);

        return $this;
    }
}