<?php
/*************************************************************************************************
 * -----------------------------------------------------------------------------------------------
 * Ocara开源框架 数据库接口基类DatabaseBase
 * Copyright (c) http://www.ocara.cn All rights reserved.
 * -----------------------------------------------------------------------------------------------
 * @author Lin YiHu <linyhtianwa@163.com>
 ************************************************************************************************/
namespace Ocara;

defined('OC_PATH') or exit('Forbidden!');

class DatabaseBase extends Sql
{
	/**
	 * 连接属性
	 */
	protected $_plugin = null;
	protected $_isPdo = false;

	protected $_config;
	protected $_options;
	protected $_pdoName;
	protected $_prepared;
	protected $_dataType;
	protected $_isTrans;

	protected $_params = array();
	protected $_unions = array();

	protected static $paramOptions = array(
		'set', 		'where', 	'groupBy',
		'having', 	'limit', 	'orderBy',
		'more',
	);

	/**
	 * 初始化方法
	 * @param array $config
	 */
	public function __construct(array $config)
	{
		$options = array(
			'host', 'port', 'type', 'class', 'pconnect',
			'default', 'username', 'prefix', 'charset',
			'timeout', 'socket', 'options', 'keywords',
		);

		$values = array_fill(0, count($options), OC_EMPTY);
		$config = array_merge(array_combine($options, $values), $config);
		$config['name'] = ocDel($config, 'default');

		if (empty($config['charset'])) {
			$config['charset'] = 'utf8';
		}
		if (empty($config['socket'])) {
			$config['socket'] = null;
		}
		if (empty($config['options'])) {
			$config['options'] = array();
		}
		if (empty($config['prepare'])) {
			$config['prepare'] = true;
		}
		if (empty($config['pconnect'])) {
			$config['pconnect'] = false;
		}
		if (empty($config['keywords'])) {
			$config['keywords'] = array();
		} else {
			$keywords = explode(',', $config['keywords']);
			$config['keywords'] = array_map(
				'trim', array_map('strtolower', $keywords)
			);
		}

		$this->_config = $config;
		ocDel($this->_config, 'password');
		$this->initialize($config);
	}

	/**
	 * 清理绑定参数
	 */
	public function clearParams()
	{
		$this->_params = array();
	}

	/**
	 * 合并查询
	 * @param array $data
	 * @param bool $unionAll
	 */
	public function union($model, $unionAll = false)
	{
		$this->_unions[] = compact('model', 'unionAll');;
	}

	/**
	 * 初始化设置
	 * @param array $config
	 */
	public function initialize(array $config)
	{
		$config['password'] = ocGet('password', $config);
		$this->_plugin = $this->getDriver($config);

		$errorReporting = error_reporting();
		error_reporting(0);

		$this->isPconnect($config['pconnect']);
		$this->_plugin->connect();
		$this->isPrepare($config['prepare']);
		$this->setCharset($config['charset']);

		error_reporting($errorReporting);
	}

	/**
	 * 获取数据库驱动类
	 * @param array $data
	 */
	public function getDriver(array $data)
	{
		if (ocCheckExtension($this->_pdoName, false)) {
			$this->_isPdo = true;
			$object = $this->loadDatabase('Pdo');
			$object->initialize($this->getPdoParams($data));
		} else {
			$object = $this->loadDatabase($data['class']);
			$object->initialize($data);
		}

		return $object;
	}

	/**
	 * 加载数据库驱动类
	 * @param string $class
	 */
	public function loadDatabase($class)
	{
		$class = $class . 'Driver';
		$classInfo = ServiceBase::classFileExists("Database/Driver/{$class}.php");

		if ($classInfo) {
			list($path, $namespace) = $classInfo;
			include_once($path);
			$class = $namespace . 'Database\Driver' . OC_NS_SEP . $class;
			if (class_exists($class, false)) {
				$object = new $class();
				return $object;
			}
		}

		$this->showError('not_exists_database');
	}

	/**
	 * 获取配置选项
	 * @param null $name
	 */
	public function getConfig($name = null)
	{
		if (func_num_args()) {
			if (ocEmpty($name)) {
				return null;
			}
			return ocGet((string)$name, $this->_config);
		}

		return $this->_config;
	}

	/**
	 * 设置结果集返回数据类型
	 * @param string $dataType
	 */
	public function setDataType($dataType)
	{
		$dataType = strtolower($dataType);

		if (empty($dataType)) {
			$dataType = ocConfig('MODEL_QUERY_DATA_TYPE', false);
		}

		$this->_dataType = $dataType == 'object' ? 'object' : 'array';
	}

	public function getUnions()
	{
		return $this->_unions;
	}

	/**
	 * 执行SQL语句
	 * @param string $sql
	 * @param bool $debug
	 * @param bool $query
	 * @param bool $required
	 * @param bool $queryRow
	 * @param bool $count
	 */
	public function query($sql, $debug = false, $query = true, $required = true, $queryRow = false, $count = false)
	{
		$ret = $this->_checkDebug($debug, $sql);
		if ($ret) return $ret;

		if ($callback = ocConfig('CALLBACK.database.execute_sql.before', null)) {
			Call::run($callback, array($sql, date(ocConfig('DATE_FORMAT.datetime'))));
		}

		$errorReporting = error_reporting();
		error_reporting(0);

		$params = $this->_params ? array($this->_params) : array();
		if ($query) {
			foreach ($this->_unions as $union) {
				if ($count) {
					$unionData = $union['model']->getTotal(1);
				} else {
					$unionData = $union['model']->find(false, false, 1);
				}
				$sql .= $this->getUnionSql($unionData['sql'], $union['unionAll']);
				$params[] = $unionData['params'];
			}
		}

		if ($this->_prepared && $params) {
			$this->_plugin->prepare($sql);
			$this->_bindParams($params);
			$result = $this->_plugin->execute();
		} else {
			$result = $this->_plugin->query($sql);
		}

		if ($query) {
			if ($count) {
				$result = $this->_plugin->get_result($this->_dataType);
				$total = 0;
				foreach ($result as $row) {
					$total += reset($row);
				}
				$result = array(array('total' => $total));
			} else {
				$result = $this->_plugin->get_result($this->_dataType, $queryRow);
			}
		}

		error_reporting($errorReporting);
		$ret = $this->checkError($result, $sql, $required);
		return $ret;
	}

	/**
	 * 查询一条记录
	 * @param string $sql
	 * @param bool $debug
	 * @param bool $count
	 */
	public function queryRow($sql, $debug = false, $count = false)
	{
		$result = $this->query($sql, $debug, true, true, true, $count);

		if ($result && empty($debug)) {
			$result = reset($result);
		}

		return $result;
	}

	/**
	 * 是否预处理
	 * @param bool $pconnect
	 */
	public function isPconnect($pconnect = true)
	{
		if (func_num_args()) {
			$this->_pconnect = $pconnect ? true : false;
			$this->_plugin->is_pconnect($pconnect);
		}
		return $this->_pconnect;
	}

	/**
	 * 是否预处理
	 * @param bool $prepare
	 */
	public function isPrepare($prepare = true)
	{
		if (func_num_args()) {
			$this->_prepared = $prepare ? true : false;
			$this->_plugin->is_prepare($prepare);
		}
		return $this->_prepared;
	}

	/**
	 * 绑定参数
	 * @param string $type
	 * @param array $option
	 * @param scalar $params
	 */
	public function bind($type, $option, &$params)
	{
		if (is_string($type)) {
			$type = explode(OC_EMPTY, strtolower($type));
		} elseif (is_array($type)) {
			$type = array_map('strtolower', $type);
		}

		$types = $this->mapParamType($type);
		$data = array();

		foreach ($params as $key => &$value) {
			$dataType = empty($types[$key]) ? $this->parseParamType($value) : $types[$key];
			$data[] = array('type' => $dataType, 'value' => $value);
		}

		$option = strtolower($option);
		$this->_params[$option] = array_merge($this->_params[$option], $data);
	}

	/**
	 * 扩展函数（字段类型映射）
	 * @param array $types
	 * @return array
	 */
	protected function mapParamType($types)
	{
		return array();
	}

	/**
	 * 绑定参数
	 * @param array $params
	 */
	private function _bindParams(array $params)
	{
		$types = false;
		$data = array();
		$paramData = array();

		foreach ($params as $row) {
			foreach (self::$paramOptions as $option) {
				if (!empty($row[$option])) {
					$paramData = array_merge($paramData, $row[$option]);
				}
			}
		}

		foreach ($paramData as $key => &$value) {
			$type = $this->parseParamType($value);
			if ($this->_isPdo) {
				$this->_plugin->bind_param($key + 1, $value, $type);
			} else {
				$types = $types . $type;
				$data[] = &$value;
			}
		}

		if (!$this->_isPdo) {
			array_unshift($data, $types);
			call_user_func_array(array($this->_plugin, 'bind_param'), $data);
		}
	}

	/**
	 * 解析参数类型
	 * @param mixed $value
	 */
	private function parseParamType($value)
	{
		$mapTypes = $this->_plugin->get_param_types();

		if (is_numeric($value)) {
			return $mapTypes['integer'];
		} elseif (is_string($value)) {
			return $mapTypes['string'];
		} elseif (is_bool($value)) {
			return $mapTypes['boolean'];
		} else {
			return $mapTypes['string'];
		}
	}

	/**
	 * 获取最后一次插入记录的自增ID
	 * @param string $sql
	 * @param bool $debug
	 */
	public function getInsertId($sql = false, $debug = false)
	{
		if (empty($sql)) $sql = $this->getLastIdSql();
		$result = $this->queryRow($sql, $debug);
		return $result ? $result['id'] : false;
	}

	/**
	 * 检测表是否存在
	 * @param string $table
	 * @param bool $required
	 */
	public function tableExists($table, $required = false)
	{
		$table = $this->getTableName($table);
		$sql = $this->getSelectSql(1, $table, array('limit' => 1));
		$ret = $this->query($sql, false, false, false);

		if ($required) {
			return $ret;
		} else {
			return $this->errorExists() == false;
		}
	}

	/**
	 * 插入
	 * @param string $table
	 * @param array $data
	 * @param bool $debug
	 */
	public function insert($table, $data = null, $debug = false)
	{
		if (empty($data) || !is_array($data)) {
			$this->showError('fault_save_data');
		}

		$table = $this->getTableName($table);
		$sql = $this->getInsertSql($table, $data);

		$ret = $this->_checkDebug($debug, $sql);
		if ($ret) return $ret;
		$insertResult = $data ? $this->query($sql, false, false) : false;

		return $insertResult ? $this->getInsertId() : false;
	}

	/**
	 * 更新
	 * @param string $table
	 * @param string|array $data
	 * @param string|array $condition
	 * @param bool $debug
	 */
	public function update($table, $data = false, $condition = null, $debug = false)
	{
		if (empty($data)) {
			$this->showError('fault_save_data');
		}

		$table = $this->getTableName($table);
		$condition = $this->parseCondition($condition);
		$sql = $this->getUpdateSql($table, $data, $condition);

		$ret = $this->_checkDebug($debug, $sql);
		if ($ret) return $ret;
		$ret = $data ? $this->query($sql, $debug, false) : false;

		return $ret;
	}

	/**
	 * 删除记录
	 * @param string $table
	 * @param string|array $condition
	 * @param bool $debug
	 */
	public function delete($table, $condition, $debug = false)
	{
		$table = $this->getTableName($table);
		$condition = $this->parseCondition($condition);
		$sql = $this->getDeleteSql($table, $condition);

		return $this->query($sql, $debug, false);
	}

	/**
	 * 获取表全名
	 * @param string $table
	 */
	public function getTableName($table)
	{
		if (preg_match('/^' . OC_SQL_TAG . '(.*)$/i', $table, $mt)) {
			return $mt[1];
		}

		if (preg_match('/(\w+)\.(\w+)/i', $table, $mt)) {
			$databaseName = $mt[1];
			$table = $mt[2];
		} else {
			$databaseName = $this->_config['name'];
			if ($this->_config['prefix']) {
				$table = $this->_config['prefix'] . $table;
			}
		}

		return $this->getTableNameSql($databaseName, $table);
	}

	/**
	 * 选择数据库
	 * @param string $name
	 */
	public function selectDatabase($name)
	{
		$result = $this->selectDb($name);

		if ($result) {
			$this->_config['name'] = $name;
		} else {
			$this->showError('failed_select_database');
		}

		return $result;
	}

	/**
	 * 获取关键字
	 */
	public function getKeywords()
	{
		return $this->_config['keywords'] ? $this->_config['keywords'] : array();
	}

	/**
	 * 是否正在事务过程中
	 * @param boolean $isTrans
	 * @return mixed
	 */
	public function isTrans()
	{
		return $this->_isTrans;
	}

	/**
	 * 事务开始
	 */
	public function transBegin()
	{
		if (!$this->_isTrans) {
			$this->trans('begin');
			$this->_isTrans = true;
		}
	}

	/**
	 * 事务提交
	 */
	public function transCommit()
	{
		if ($this->_isTrans) {
			$this->trans('commit');
			$this->_isTrans = false;
		}
	}

	/**
	 * 事务回滚
	 */
	public function transRollback()
	{
		if ($this->_isTrans) {
			$this->trans('rollback');
			$this->_isTrans = false;
		}
	}

	/**
	 * 显示错误信息
	 */
	public function showError()
	{
		if ($this->errorExists()) {
			if ($this->isTrans()) {
				$this->transRollback();
			}
			Error::show($this->getError());
		}
	}

	/**
	 * 获取错误信息
	 */
	public function getError()
	{
		return $this->_plugin->error() . ' Error no ：' . $this->_plugin->error_no();
	}

	/**
	 * 获取错误列表
	 */
	public function getErrorList()
	{
		return $this->_plugin->error_list();
	}
	
	/**
	 * 获取错误代码
	 */
	public function getErrorCode()
	{
		return $this->_plugin->error_no();
	}
	
	/**
	 * 错误是否存在
	 */
	public function errorExists()
	{
		return (boolean)$this->_plugin->error_no();
	}

	/**
	 * 检测错误
	 * @param array|object $ret
	 * @param stirng $sql
	 * @param bool $required
	 */
	public function checkError($ret, $sql, $required = true)
	{
		$errorExists = $this->errorExists();
		$error = $errorExists ? $this->getError() : null;

		if ($sql) {
			$callback = ocConfig('CALLBACK.database.execute_sql.after', false);
			if ($callback) {
				$params = array($sql, $errorExists, $error,$ret, date(ocConfig('DATE_FORMAT.datetime')));
				Call::run($callback, $params);
			}
		}

		if ($required && $errorExists) {
			return $this->showError($error);
		}

		return  $ret;
	}

	/**
	 * debug参数检查
	 * @param boolean $debug
	 * @param string $sql
	 */
	private function _checkDebug($debug, $sql)
	{
		if ($debug) {
			$ret = array('sql' => $sql, 'params' => $this->_params);
			$this->_params = array();
			if ($debug === Database::DEBUG_RETURN) {
				return $ret;
			}
			if ($debug === Database::DEBUG_PRINT
				|| $debug === Database::DEBUG_DUMP
			) {
				if (OC_SYS_MODEL == 'develop') {
					if ($debug === Database::DEBUG_DUMP) {
						ocDump($ret);
					} else {
						ocPrint($ret);
					}
				} else {
					$this->showError('invalid_debug');
				}
			} else {
				$this->showError('fault_debug_param');
			}
		}
		
		return false;
	}
}