<?php

namespace app;

use ArrayAccess;
use Exception;

class Env implements ArrayAccess
{
    /**
     * 环境变量数据
     * @var array
     */
    protected static array $data = [];

    /**
     * 数据转换映射
     * @var array
     */
    protected static array $convert = [
        'true'  => true,
        'false' => false,
        'off'   => false,
        'on'    => true,
        '1'     => true,
        '0'     => false,
    ];

    /**
     * 读取环境变量定义文件
     * @access public
     * @param string $file 环境变量定义文件
     * @return void
     */
    public static function load(string $file): void
    {
        if (!is_file($file)) {
            return;
        }

        $env = parse_ini_file($file, true);
        self::set($env);
    }

    /**
     * 获取环境变量值
     * @param string|null $name 环境变量名
     * @param mixed|null $default 默认值
     * @return array|false|mixed|string|null
     */
    public static function get(string $name = null, mixed $default = null): mixed
    {
        if (is_null($name)) {
            return self::$data;
        }
        $name = strtoupper(str_replace('.', '_', $name));
        if (isset(self::$data[$name])) {
            $result = self::$data[$name];
            if (is_string($result) && isset(self::$convert[$result])) {
                return self::$convert[$result];
            }
            return $result;
        }
        return self::getEnv($name, $default);
    }

    /**
     * @param string $name
     * @param $default
     * @return array|false|mixed|string|null
     */
    protected static function getEnv(string $name, $default = null): mixed
    {
        $result = getenv('PHP_' . $name);
        if (false === $result) {
            return $default;
        }
        if (isset(self::$convert[$result])) {
            $result = self::$convert[$result];
        }
        if (!isset(self::$data[$name])) {
            self::$data[$name] = $result;
        }
        return $result;
    }

    /**
     * 设置环境变量值
     * @access public
     * @param array|string $env 环境变量
     * @param mixed|null $value 值
     * @return void
     */
    public static function set(array|string $env, mixed $value = null): void
    {
        if (is_array($env)) {
            $env = array_change_key_case($env, CASE_UPPER);
            foreach ($env as $key => $val) {
                if (is_array($val)) {
                    foreach ($val as $k => $v) {
                        self::$data[$key . '_' . strtoupper($k)] = $v;
                    }
                } else {
                    self::$data[$key] = $val;
                }
            }
        } else {
            $name = strtoupper(str_replace('.', '_', $env));
            self::$data[$name] = $value;
        }
    }

    /**
     * 检测是否存在环境变量
     * @access public
     * @param string $name 参数名
     * @return bool
     */
    public static function has(string $name): bool
    {
        return !is_null(self::get($name));
    }

    /**
     * 设置环境变量
     * @param string $name 参数名
     * @param mixed $value 值
     * @return void
     */
    public function __set(string $name, mixed $value): void
    {
        $this->set($name, $value);
    }

    /**
     * 获取环境变量
     * @access public
     * @param string $name 参数名
     * @return mixed
     */
    public function __get(string $name)
    {
        return $this->get($name);
    }

    /**
     * 检测是否存在环境变量
     * @access public
     * @param string $name 参数名
     * @return bool
     */
    public function __isset(string $name): bool
    {
        return $this->has($name);
    }

    /**
     * ArrayAccess offsetSet
     * @param mixed $offset
     * @param mixed $value
     * @return void
     */
    public function offsetSet(mixed $offset, mixed $value): void
    {
        $this->set($offset, $value);
    }

    /**
     * ArrayAccess offsetExists
     * @param mixed $offset
     * @return bool
     */
    public function offsetExists(mixed $offset): bool
    {
        return $this->__isset($offset);
    }

    /**
     * ArrayAccess offsetUnset
     * @param mixed $offset
     * @return void
     * @throws Exception
     */
    public function offsetUnset(mixed $offset): void
    {
        throw new Exception('not support: unset');
    }

    public function offsetGet(mixed $offset): mixed
    {
        return $this->get($offset);
    }
}