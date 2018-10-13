<?php
/*************************************************************************************************
 * -----------------------------------------------------------------------------------------------
 * Ocara开源框架    基类Base
 * Copyright (c) http://www.ocara.cn All rights reserved.
 * -----------------------------------------------------------------------------------------------
 * @author Lin YiHu <linyhtianwa@163.com>
 ************************************************************************************************/
namespace Ocara\Core;

use Ocara\Core\Basis;
use Ocara\Core\Container;

defined('OC_PATH') or exit('Forbidden!');

abstract class Base extends Basis
{
	/**
	 * @var $_error 错误信息
	 * @var $_route 路由信息
	 * @var $_properties 自定义属性
	 */
	protected $_route;
	protected $_plugin;
    protected $_event;

    protected $_events = array();
    protected $_traits = array();

    /**
     * 实例化
     * @param mixed $params
     * @return static
     */
    public static function build($params = null)
    {
        return call_user_func_array('ocClass', array(self::getClass(), func_get_args()));
    }

    /**
     * 获取自定义属性
     * @param string $name
     * @param mixed $args
     * @return array|mixed
     */
    public function &getProperty($name = null)
    {
        if (isset($name)) {
            if (array_key_exists($name, $this->_properties)) {
                return $this->_properties[$name];
            }
            if (method_exists($this, '__none')) {
                $this->__none($name);
            } else {
                ocService()->error->show('no_property', array($name));
            }
        }

        return $this->_properties;
    }

	/**
	 * 魔术方法-调用未定义的方法时
	 * @param string $name
	 * @param array $params
	 * @return mixed
	 * @throws Exception
	 */
	public function __call($name, $params)
	{
		$obj = $this;
		while (isset($obj->_plugin) && is_object($obj->_plugin)) {
			if (method_exists($obj->_plugin, $name)) {
				return call_user_func_array(array(&$obj->_plugin, $name), $params);
			} else {
				$obj = $obj->_plugin;
			}
		}

        if (isset($this->_traits[$name])) {
            return call_user_func_array($this->_traits[$name], $params);
        }

        ocService()->error->show('no_method', array($name));
	}

    /**
     * 魔术方法-调用未定义的静态方法时
     * >= php 5.3
     * @param string $name
     * @param array $params
     * @throws Exception
     */
    public static function __callStatic($name, $params)
    {
        return ocService()->error->show('no_method', array($name));
    }

    /**
     * 魔术方法-获取自定义属性
     * @param string $key
     * @return mixed
     * @throws Exception
     */
    public function __get($key)
    {
        if ($this->hasProperty($key)) {
            $value = $this->getProperty($key);
            return $value;
        }

        if (method_exists($this, '__none')) {
            $value = $this->__none($key);
            return $value;
        }

        ocService()->error->show('no_property', array($key));
    }

	/**
	 * 获取日志对象
	 * @param string $logName
	 */
	public static function log($logName)
	{
		return ocContainer()->create('log', array($logName));
	}

	/**
	 * 获取插件
	 */
	public function plugin()
	{
		if (property_exists($this, '_plugin') && is_object($this->_plugin)) {
			return $this->_plugin;
		}

        ocService()->error->show('no_plugin');
	}

    /**
     * 设置或获取事件
     * @param $eventName
     * @return mixed
     */
    public function event($eventName)
    {
        if (!isset($this->_events[$eventName])) {
            $event = ocContainer()->create('event');
            $event->setName($eventName);
            $this->_events[$eventName] = $event;
            if ($this->_event && method_exists($this->_event, $eventName)) {
                $event->clear();
                $event->append(array(&$this->_event, $eventName), $eventName);
            }
        }

        return $this->_events[$eventName];
    }

    /**
     * 绑定事件资源包
     * @param $eventObject
     * @return $this
     */
    public function bindEvents($eventObject)
    {
        if (is_string($eventObject) && class_exists($eventObject)) {
            $eventObject = new $eventObject();
        }

        if (is_object($eventObject)) {
            $this->_event = $eventObject;
        }

        return $this;
    }

    /**
     * 动态行为扩展
     * @param string|object $name
     * @param $function
     */
    public function traits($name, $function = null)
    {
        if (is_string($name)) {
            $this->_traits[$name] = $function;
        } elseif (is_object($name)) {
            if (is_array($function)) {
                foreach ($function as $name => $value) {
                    $setMethod = 'set' . ucfirst($name);
                    $name->$setMethod($value);
                }
            }
            $methods = get_class_methods($name);
            foreach ($methods as $method) {
                $this->traits($method, array($name, $method));
            }
        }
    }
}