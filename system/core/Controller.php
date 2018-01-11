<?php
/*************************************************************************************************
 * -----------------------------------------------------------------------------------------------
 * Ocara开源框架   应用控制器基类Controller
 * Copyright (c) http://www.ocara.cn All rights reserved.
 * -----------------------------------------------------------------------------------------------
 * @author Lin YiHu <linyhtianwa@163.com>
 ************************************************************************************************/
namespace Ocara;
use Ocara\Interfaces\Controller as ControllerInterface;

defined('OC_PATH') or exit('Forbidden!');

class Controller extends Base implements ControllerInterface
{
	/**
	 * @var $_provider 控制器提供者
	 */
	protected $_provider;

	/**
	 * 初始化设置
	 * @param array $route
	 */
	public function init(array $route)
	{
		$controllerType = Route::getControllerType($route['module'], $route['controller']);
		$provider = 'Ocara\Controller\Provider\\' . $controllerType;
		$this->_provider = new $provider(compact('route'));
		$this->_provider->init();
		$this->_provider->bindEvents($this);

		Config::set('CALLBACK.ajax.return_result', array($this->_provider, 'formatAjaxResult'));

		method_exists($this, '_start') && $this->_start();
		method_exists($this, '_module') && $this->_module();
		method_exists($this, '_control') && $this->_control();
	}

	/**
	 * 获取当前的提供者
	 * @return 控制器提供者
	 */
	public function provider()
	{
		return $this->_provider;
	}

	/**
	 * 执行动作
	 * @param string $actionMethod
	 */
	public function doAction($actionMethod)
	{
		$doWay = $this->_provider->getDoWay();

		if (!$this->_provider->isSubmit()) {
			if (method_exists($this, '_isSubmit')) {
				$this->_provider->isSubmit($this->_isSubmit());
			} elseif ($this->submitMethod() == 'post') {
				$this->_provider->isSubmit(Request::isPost());
			}
		}

		if ($doWay == 'common') {
			$this->doCommonAction();
		} elseif($doWay == 'ajax') {
			$this->doAjaxAction();
		}
	}

	/**
	 * 执行动作（类方法）
	 */
	public function doCommonAction()
	{
		method_exists($this, '_action') && $this->_action();
		method_exists($this, '_form') && $this->_form();
		$this->checkForm();

		if (Request::isAjax()) {
			$data = OC_EMPTY;
			if (method_exists($this, '_ajax')) {
				$data = $this->_ajax();
			}
			$this->_provider->ajaxReturn($data);
		} elseif ($this->_provider->isSubmit() && method_exists($this, '_submit')) {
			$this->_submit();
			$this->_provider->formManager->clearToken();
		} else{
			method_exists($this, '_display') && $this->_display();
			$this->_provider->display();
		}
	}

	/**
	 * 执行动作
	 * @param string $actionMethod
	 */
	public function doAjaxAction($actionMethod)
	{
		if ($actionMethod == '_action') {
			$result = $this->_action();
		} else {
			$result = $this->$actionMethod();
		}

		$this->_provider->display($result);
	}

	/**
	 * 执行动作（返回值）
	 * @param string $method
	 * @param array $params
	 * @return mixed
	 * @throws \Ocara\Exception
	 */
	public function doReturnAction($method, array $params = array())
	{
		if (method_exists($this, $method)) {
			return call_user_func_array(array($this, $method), $params);
		} else {
			Error::show('no_action_return');
		}
	}

	/**
	 * 获取不存在的属性时
	 * @param string $key
	 * @return array|null
	 */
	public function &__get($key)
	{
		if ($this->hasProperty($key)) {
			$value = &$this->getProperty($key);
			return $value;
		}
		if ($instance = $this->_provider->getService($key)) {
			return $instance;
		}
		Error::show('no_property', array($key));
	}

	/**
	 * 调用未定义的方法时
	 * @param string $name
	 * @param array $params
	 * @return mixed
	 * @throws Exception\Exception
	 */
	public function __call($name, $params)
	{
		if (is_object($this->_provider) && method_exists($this->_provider, $name)) {
			return call_user_func_array(array(&$this->_provider, $name), $params);
		}
		if (is_object($this->view) && method_exists($this->view, $name)) {
			return call_user_func_array(array(&$this->view, $name), $params);
		}
		parent::_call($name, $params);
	}
}