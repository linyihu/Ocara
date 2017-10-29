<?php
/*************************************************************************************************
 * -----------------------------------------------------------------------------------------------
 * Ocara开源框架   Session数据库方式处理类SessionDB
 * Copyright (c) http://www.ocara.cn All rights reserved.
 * -----------------------------------------------------------------------------------------------
 * @author Lin YiHu <linyhtianwa@163.com>
 ************************************************************************************************/
namespace Ocara\Session;
use Ocara\Base;
use Ocara\Error;
use Ocara\ModelBase;
use \Exception;

defined('OC_PATH') or exit('Forbidden!');

class SessionDB extends Base
{
	protected $_plugin = null;

	/**
	 * 析构函数
	 */
	public function __construct()
	{
		$location = ocConfig('SESSION.location', '\Ocara\Service\Model\Session', true);
		$this->_plugin = new $location();

		if (!(is_object($this->_plugin) && $this->_plugin instanceof ModelBase)) {
			Error::show('failed_db_connect');
		}
	}

	/**
	 * session打开
	 */
	public function open()
	{
		return is_object($this->_plugin) && $this->_plugin instanceof ModelBase;
	}

	/**
	 * session关闭
	 */
	public function close()
	{
		$this->_plugin = null;
		return true;
	}

	/**
	 * 读取session信息
	 * @param string $id
	 */
	public function read($id)
	{
		$sessionData = $this->_plugin->read($id);
		return $sessionData ? stripslashes($sessionData) : OC_EMPTY;
	}

	/**
	 * 保存session
	 * @param string $id
	 * @param string $data
	 */
	public function write($id, $data)
	{
		$datetimeFormat = ocConfig('DATE_FORMAT.datetime');
		$maxLifeTime = @ini_get('session.gc_maxlifetime');
		$now = date($datetimeFormat);
		$expires = date($datetimeFormat, strtotime("{$now} + {$maxLifeTime} second"));

		$data = array(
			'session_id' 	  	  => $id,
			'session_expire_time' => $expires,
			'session_data' 	  	  => stripslashes($data)
		);

		$this->_plugin->write($data);
		$result = $this->_plugin->errorExists();

		return $result === true;
	}

	/**
	 * 销毁session
	 * @param string $id
	 */
	public function destroy($id)
	{
		$this->_plugin->destory($id);
		$result = $this->_plugin->errorExists();

		return $result === true;
	}

	/**
	 * Session垃圾回收
	 * @param integer $saveTime
	 */
	public function gc($saveTime = false)
	{
		$this->_plugin->clear();
		$result = $this->_plugin->errorExists();

		return $result === true;
	}
}