<?php
/*************************************************************************************************
 * -----------------------------------------------------------------------------------------------
 * Ocara开源框架   URL类Url
 * Copyright (c) http://www.ocara.cn All rights reserved.
 * -----------------------------------------------------------------------------------------------
 * @author Lin YiHu <linyhtianwa@163.com>
 ************************************************************************************************/
namespace Ocara;
use Ocara\Ocara;

defined('OC_PATH') or exit('Forbidden!');

class Url extends Base
{
	const DEFAULT_TYPE 	= 1; //默认类型
	const DIR_TYPE 		= 2; //伪目录类型
	const PATH_TYPE 	= 3; //伪路径类型
	const STATIC_TYPE 	= 4; //伪静态类型
	
	/**
	 * 是否虚拟URL地址
	 * @param string $urlType
	 * @return bool
	 */
	public function isVirtualUrl($urlType)
	{
		return in_array($urlType, array(self::DIR_TYPE, self::PATH_TYPE, self::STATIC_TYPE));
	}

	/**
	 * URL请求参数解析
	 * @param string $url
	 * @return array|bool|string
	 * @throws Exception\Exception
	 */
	public function parseGet($url = null)
	{
		if (empty($url)) {
			if (OC_PHP_SAPI == 'cli') {
				$url = trim(ocGet('argv.1', $_SERVER), OC_DIR_SEP);
			} else {
				$localUrl = $_SERVER['DOCUMENT_ROOT'] . OC_REQ_URI;
				if ($localUrl == $_SERVER['SCRIPT_FILENAME']) {
					return array();
				}
				$url = trim(OC_REQ_URI, OC_DIR_SEP);
			}
		}

		if (empty($url)) return array();

		$result = $this->check($url, OC_URL_ROUTE_TYPE);
		if ($result === null) {
			Ocara::services()->error->show('fault_url');
		}

		if ($this->isVirtualUrl(OC_URL_ROUTE_TYPE)) {
			$get = trim($result[3]);
			if ($get) {
				$get = explode(OC_DIR_SEP, trim($result[3], OC_DIR_SEP));
			} else {
				$get[0] = null;
			}
			if (isset($result[11])) {
				parse_str($result[11], $extends);
				$get[] = $extends;
			}
		} else {
			$params = explode('&', $result);
			$get = array();
			foreach ($params as $param) {
				$row = explode('=', $param);
				$key = ocGet(0, $row);
				$value = ocGet(1, $row);
				$get[$key] = $value;
			}

			$routeParamName = ocConfig('ROUTE_PARAM_NAME', 'r');
			if (isset($get[$routeParamName])) {
				$route = explode('/', ocDel($get, $routeParamName));
				$route[1] = isset($route[1]) ? $route[1] : null;
				$route[2] = isset($route[2]) ? $route[2] : null;
				$get = array_merge($route, $get);
			}
		}

		return $get;
	}

	/**
	 * 检测URL
	 * @param string $url
	 * @param string $urlType
	 * @return null
	 */
	public function check($url, $urlType)
	{
		$url = str_replace('\\', OC_DIR_SEP, $url);
		$el  = '[^\/\&\?]';
		$get = null;

		if ($this->isVirtualUrl($urlType)) {
			$str  = $urlType == self::PATH_TYPE ? 'index\.php[\/]?' : false;
			$el   = '[^\/\&\?]';
			$mvc  = '\w*';
			$mvcs = $mvc . '\/';

			if ($urlType == self::STATIC_TYPE && $url != OC_DIR_SEP) {
				$file = "\.html?";
			} else {
				$file = OC_EMPTY;
			}

			$tail = "(\/\w+\.\w+)?";
			$tail = $file . "({$tail}\?(\w+={$el}*(&\w+={$el}*)*)?(#.*)?)?";
			$exp  = "/^(\w+:\/\/\w+(\.\w)*)?{$str}(({$mvc})|({$mvcs}{$mvc})|({$mvcs}{$mvcs}{$mvc}(\/({$el}*\/?)+)*))?{$tail}$/i";
			if (preg_match($exp, $url, $mt)){
				$get = $mt;
			}
		} else {
			$get = parse_url($url, PHP_URL_QUERY);
		}

		return $get;
	}

	/**
	 * 新建URL
	 * @param string|array $route
	 * @param string|array $params
	 * @param bool $relative
	 * @param integer $urlType
	 * @param bool $static
	 * @return bool|string
	 */
	public function create($route, $params = array(), $relative = false, $urlType = null, $static = true)
	{
		$route = Ocara::parseRoute($route);
		if (empty($route)) return false;

		extract($route);
		$urlType = $urlType ? : OC_URL_ROUTE_TYPE;

		if (is_numeric($params) || is_string($params)) {
			$array = array_chunk(explode(OC_DIR_SEP, $params), 2);
			$params = array();
			foreach ($array as $value) {
				$params[reset($value)] = isset($value[1]) ? $value[1] : null;
			}
		} elseif (!is_array($params)) {
			$params = array();
		}

		if ($static && Ocara::services()->staticPath->open) {
			list($file, $args) = Ocara::services()->staticPath->getStaticFile($module, $controller, $action, $params);
			if ($file && is_file(ocPath('static', $file))) {
				return $relative ? OC_DIR_SEP . $file : OC_ROOT_URL . $file;
			}
		}
		
		if ($this->isVirtualUrl($urlType)) {
			if ($module) {
				$query = array($module, $controller, $action);
			} else {
				$query = array($controller, $action);
			}
			
			$route     = implode(OC_DIR_SEP, $query);
			$query     = $params ? OC_DIR_SEP . implode(OC_DIR_SEP, $this->devideQuery($params)) : false;
			$paramPath = $urlType == self::PATH_TYPE ? OC_INDEX_FILE . OC_DIR_SEP : false;
			$paramPath = $paramPath . $route . $query;
			$paramPath = $urlType == self::STATIC_TYPE ? $paramPath . '.html' : $paramPath;
		} else {
			$route = $query = array();
			if ($module) {
				$route['m'] = $module;
			}

			$route['c'] = $controller;
			$route['a'] = $action;

			foreach ($route as $key => $value) {
				$query[] = $key . '=' . $value;
			}
			foreach ($params as $key => $value) {
				$query[] = $key . '=' . $value;
			}

			$paramPath = OC_INDEX_FILE . '?' . implode('&', $query);
		}
		
		return $relative ? OC_DIR_SEP . $paramPath : OC_ROOT_URL . $paramPath;
	}

	/**
	 * 格式化参数数组
	 * @param array $params
	 * @return array
	 */
	public function devideQuery(array $params)
	{
		$result = array();
		
		if ($params) {
			if (0) return array_values($params);
			foreach ($params as $key => $value) {
				$result[] = $key;
				$result[] = $value;
			}
		}
		
		return $result;
	}

	/**
	 * 添加查询字符串参数
	 * @param array $params
	 * @param string $url
	 * @param string $urlType
	 * @return string
	 * @throws Exception\Exception
	 */
	public function addQuery(array $params, $url = null, $urlType = null)
	{
		$urlType = $urlType ? : OC_URL_ROUTE_TYPE;
		$data    = $this->parseUrl($url);
		
		if ($url) {
			$uri = $data['path'] . ($data['query'] ? '?' . $data['query'] : false);
		} else {
			$uri = OC_REQ_URI;
		}

		$result = $this->check($uri, $urlType);
		if ($result === null) {
			Ocara::services()->error->show('fault_url');
		}

		if ($this->isVirtualUrl($urlType)) {
			$data['path'] = $result[3] . OC_DIR_SEP . implode(OC_DIR_SEP, $this->devideQuery($params));
		} else {
			parse_str($data['query'], $query);
			$data['query'] = $this->buildQuery($query, $params);
		}
		
		return $this->buildUrl($data);
	}

	/**
	 * 解析URL
	 * @param string $url
	 */
	public function parseUrl($url = null)
	{
		$fields = array(
			'scheme', 	'host', 	'port',
			'username', 'password',  
			'path', 	'query',
		);

		if ($url) {
			$data  = array_merge(array_fill_keys($fields, null), parse_url($url));
		} else {
		    $request = Ocara::services()->request;
			$values = array(
				OC_PROTOCOL,
                $request->getServer('HTTP_HOST'),
                $request->getServer('SERVER_PORT'),
                $request->getServer('PHP_AUTH_USER'),
                $request->getServer('PHP_AUTH_PW'),
                $request->getServer('REDIRECT_URL'),
                $request->getServer('QUERY_STRING'),
			);
			$data = array_combine($fields, $values);
		}
		
		return $data;
	}

	/**
	 * 生成查询字符串
	 * @param array $params
	 */
	public function buildQuery(array $params)
	{
		$array = array();
		
		foreach ($params as $key => $value) {
			$array[] = $key . '=' . $value;
		}
		
		return implode('&', $array);
	}

	/**
	 * 生成URL
	 * @param array $data
	 */
	public function buildUrl(array $data)
	{
		$url = $data['scheme'] . '://';
		if ($data['username']) {
			$url = $url . "{$data['username']}:{$data['password']}@";
		}

		$url = $url . $data['host'];
		if ($data['port']) {
			$url = $url . ($data['port'] == '80' ? false : ':' . $data['port']);
		}

		$url = $url . $data['path'];
		if ($data['query']) {
			$url = $url . '?' . $data['query'];
		}
		
		return $url;
	}
}