<?php
/*************************************************************************************************
 * -----------------------------------------------------------------------------------------------
 * Ocara开源框架   数据库模型类Database
 * Copyright (c) http://www.ocara.cn All rights reserved.
 * -----------------------------------------------------------------------------------------------
 * @author Lin YiHu <linyhtianwa@163.com>
 ************************************************************************************************/
namespace Ocara\Model;
use Ocara\Ocara;
use Ocara\Request;
use Ocara\Error;
use Ocara\Call;
use Ocara\Cache;
use Ocara\FormToken;
use Ocara\Database as DatabaseFactory;
use Ocara\DatabaseBase;
use Ocara\ModelBase;
use Ocara\Iterator\Database\ObjectRecords;

defined('OC_PATH') or exit('Forbidden!');

abstract class Database extends ModelBase
{

	/**
	 * @var @_primary 主键字段列表
	 * @var $_primaries 主键字段数组
	 */
	protected $_plugin;
	protected $_primary;
	protected $_table;
	protected $_server;
	protected $_dataType;
	protected $_fields;
	protected $_alias;

	private $_tag;
	private $_master;
	private $_slave;
	private $_database;
	private $_tableName;
	private $_isOrm;
	private $_insertId;

	private $_selected = array();
	private $_relations = array();
	private $_sql = array();
	private $_primaries = array();
	private $_joins = array();
	private $_changes = array();
	private $_unions = array();

	private static $_config = array();
	private static $_requirePrimary;

	/**
	 * Model constructor.
	 */
	public function __construct()
	{
		$this->init();
	}

	/**
	 * 初始化
	 */
	public function init()
	{
		if (self::$_requirePrimary === null) {
			$required = ocConfig('MODEL_REQUIRE_PRIMARY', true);
			self::$_requirePrimary = $required ? true : false;
		}

		if (self::$_requirePrimary && empty($this->_primary)) {
			Error::show('no_primaries');
		}

		$this->_tag = self::getClass();
		$this->_alias = $this->_alias ? $this->_alias : 'a';
		$this->_tableName = empty($this->_table) ? lcfirst(self::getClassName()) : $this->_table;

		$this->_join(false, $this->_tag, $this->_alias);
		$this->setDataType($this->_dataType);
		self::loadConfig($this->_tag);

		if ($this->_primary) {
			$this->_primaries = explode(',', $this->_primary);
		}

		$this->bindEvents($this);

		if (method_exists($this, '_model')) $this->_model();
		return $this;
	}

	/**
	 * 获取Model标记
	 * @return string
	 */
	public function getTag()
	{
		return $this->_tag;
	}

	/**
	 * 获取表名
	 * @return mixed
	 */
	public function getTableName()
	{
		return $this->_tableName;
	}

	/**
	 * 获取表的全名（包括前缀）
	 * @return mixed
	 */
	public function getTableFullname()
	{
		return $this->connect()->getTableFullname($this->_tableName);
	}

	/**
	 * 获取当前服务器
	 * @return mixed
	 */
	public function getServer()
	{
		return $this->_server;
	}

	/**
	 * 获取当前数据库
	 * @return mixed
	 */
	public function getDatabase()
	{
		return $this->_database;
	}

	/**
	 * 执行分库分表
	 * @param array $data
	 * @return $this
	 */
	public function sharding(array $data = array())
	{
		if (method_exists($this, '_sharding')) {
			$this->_sharding($data);
		}

		return $this;
	}

	/**
	 * 加载配置文件
	 */
	public static function loadConfig($class)
	{
		if (!empty(self::$_config[$class])) {
			return true;
		}

		$modelConfig['JOIN'] = array();
		$modelConfig['MAP'] = array();
		$modelConfig['VALIDATE'] = array();
		$modelConfig['LANG'] = array();

		$filePath = self::getConfigPath($class);
		$path = ocPath('conf', "model/{$filePath}");

		if (ocFileExists($path)) {
			include($path);
			if (isset($CONF) && is_array($CONF)) {
				$modelConfig = array_merge(
					array_diff_key($modelConfig, $CONF),
					array_intersect_key($CONF, $modelConfig)
				);
			}
		}

		ksort($modelConfig);
		self::$_config[$class] = $modelConfig;
	}

	/**
	 * 获取配置数据
	 * @return array
	 * @param string $key
	 * @param string $field
	 */
	public static function getConfig($key = null, $field = null, $class = null)
	{
		$class = $class ? $class : self::getClass();

		if (!isset(self::$_config[$class])) {
			self::loadConfig($class);
		}

		if (isset($key)) {
			if ($field) {
				return ocGet(array($key, $field), self::$_config[$class]);
			}
			return ocGet($key, self::$_config[$class]);
		}

		return self::$_config[$class];
	}

	/**
	 * 修改字段配置
	 * @param string $key
	 * @param string $field
	 * @param mixed $value
	 * @param string $class
	 */
	public static function setConfig($key, $field, $value, $class = null)
	{
		$class = $class ? $class : self::getClass();
		$config = self::getConfig($key);
		$config[$key][$field] = $value;

		self::$_config[$class][$key] = $config;
	}

	/**
	 * 获取配置文件路径
	 */
	public static function getConfigPath($class)
	{
		$path = trim(str_ireplace('Model' . OC_NS_SEP, OC_EMPTY, $class), OC_NS_SEP);
		$filePath = implode(OC_DIR_SEP, array_map('lcfirst', explode(OC_NS_SEP, $path)));

		return $filePath . '.php';
	}

	/**
	 * 字段映射
	 * @param array $fields
	 * @param string $class
	 * @return array
	 */
	public static function mapFields(array $fields, $class)
	{
		$config = self::getConfig('MAP', null, $class);
		if (!$config) {
			return $fields;
		}

		$data = array();
		foreach ($fields as $key => $value) {
			if (isset($config[$key])) {
				$data[$config[$key]] = $value;
			} else {
				$data[$key] = $value;
			}
		}

		return $data;
	}

	/**
	 * 获取当前数据库对象
	 * @param bool $slave
	 */
	public function db($slave = false)
	{
		$name = $slave ? '_slave' : '_plugin';
		if (is_object($this->$name)) {
			return $this->$name;
		}

		Error::show('null_database');
	}

	/**
	 * 切换数据库
	 * @param string $name
	 */
	public function selectDatabase($name)
	{
		$this->_database = $name;
	}

	/**
	 * 切换数据表
	 * @param $name
	 * @param null $primary
	 */
	public function selectTable($name, $primary = null)
	{
		$this->_table = $name;
		$this->_tableName = $name;

		if ($primary) {
			$this->_primary = $primary;
			if ($this->_primary) {
				$this->_primaries = explode(',', $this->_primary);
			}
		}

		$this->_sql['tables'][$this->_alias]['fullname'] = $this->getTableName();
	}

	/**
	 * 新建ORM模型
	 * @param array $data
	 * @return $this
	 */
	public function data(array $data = array())
	{
		$data = $this->_getSubmitData($data);
		if ($data) {
			ocDel($data, FormToken::getTokenTag());
			$this->setProperty($data);
		}

		$this->_isOrm = true;
		return $this;
	}

	/**
	 * 从数据库获取数据表的字段
	 * @param bool $cache
	 * @return $this
	 */
	public function loadFields($cache = true)
	{
		if ($cache) {
			$this->_fields = self::getFieldsConfig($this->getClass());
		}

		if (!$this->_fields) {
			$this->_fields = $this->connect()->getFields($this->_tableName);
		}

		return $this;
	}

	/**
	 * 获取字段配置
	 * @param $class
	 * @return array|mixed
	 */
	public static function getFieldsConfig($class)
	{
		$filePath = self::getConfigPath($class);
		$path = ocPath('conf', "fields/{$filePath}");

		if (ocFileExists($path)) {
			return @include($path);
		}

		return array();
	}

	/**
	 * 获取字段
	 */
	public function getFields()
	{
		if (empty($this->_fields)) {
			$this->loadFields();
		}

		return $this->_fields;
	}

	/**
	 * 获取ORM数据数组
	 */
	public function toArray()
	{
		return $this->getProperty();
	}

	/**
	 * 获取ORM数据对象
	 */
	public function toObject()
	{
		$properties = $this->getProperty();
		return (object)$properties;
	}

	/**
	 * 别名字段数据映射
	 * @param array $data
	 * @return array
	 */
	public function map(array $data)
	{
		$result = array();

		if (!$this->_fields) {
			$this->loadFields();
		}

		foreach ($data as $key => $value) {
			$key = strtr($key, self::$_config[$this->_tag]['MAP']);
			if (!$this->_plugin->hasAlias($key)) {
				if (!isset($this->_fields[$key]) OR $key == FormToken::getTokenTag() OR is_object($value)) {
					continue;
				}
			}
			$result[$key] = $value;
		}

		if ($this->_fields) {
			if (!is_object($this->_plugin)) {
				$this->_plugin = $this->connect();
			}
			$result = $this->_plugin->formatFieldValues($this->_fields, $result);
		}

		return $result;
	}

	/**
	 * 字段别名映射
	 * @param string $field
	 * @param bool $return
	 * @return string
	 */
	public function mapField($field, $return = true)
	{
		if (!$this->_fields) {
			$this->loadFields();
		}

		$key = strtr($field, self::$_config[$this->_tag]['MAP']);
		if (isset($this->_fields[$key])) {
			return $key;
		}

		return $return ? $field : null;
	}

	/**
	 * 设置结果集返回数据类型
	 * @param string $dataType
	 * @return $this
	 */
	public function setDataType($dataType)
	{
		$this->_dataType = strtolower($dataType);
		return $this;
	}

	/**
	 * 清理SQL
	 */
	public function clearSql()
	{
		$this->_sql = array();
		$this->_join(false, $this->_tableName, $this->_alias);
		$this->_plugin = $this->_master;
		return $this;
	}

	/**
	 * 清理ORM数据
	 */
	public function clearData()
	{
		$this->_selected = array();
		$this->_isOrm = false;
		$this->clearProperty();
		return $this;
	}

	/**
	 * 清理Model的SQL和ORM数据
	 */
	public function clear()
	{
		$this->clearSql();
		$this->clearData();

		return $this;
	}

	/**
	 * 缓存查询的数据
	 * @param string $server
	 * @param bool $required
	 * @return $this
	 */
	public function cache($server = null, $required = false)
	{
		$server = $server ? $server : 'default';
		$this->_sql['cache'] = array($server, $required);
		return $this;
	}

	/**
	 * 规定使用主库查询
	 */
	public function master()
	{
		$this->_sql['option']['master'] = true;
		return $this;
	}

	/**
	 * 是否是ORM模型
	 */
	public function isOrm()
	{
		return $this->_isOrm;
	}

	/**
	 * 获取已修改字段数据
	 */
	public function getChanges()
	{
		$changes = array_fill_keys($this->_changes, null);
		return array_intersect_key($this->getProperty(), $changes);
	}

	/**
	 * 是否有改变某个字段
	 * @param string $key
	 * @return bool
	 */
	public function hasChanged($key = null)
	{
		if (func_get_args()) {
			return isset($this->_changes[$key]);
		}
		return !empty($this->_changes);
	}

	/**
	 * 保存记录
	 * @param array $data
	 * @param mixed $condition
	 * @param bool $debug
	 * @return bool
	 * @throws \Ocara\Exception\Exception
	 */
	private function _save($data, $condition, $debug = false)
	{
		if ($condition) {
			call_user_func_array('ocDel', array(&$data, $this->_primaries));
			if ($this->_selected && method_exists($this, '_beforeUpdate')) {
				$this->event('_beforeUpdate')->fire();
			}
		} else {
			if (method_exists($this, '_beforeCreate')) {
				$this->event('_beforeCreate')->fire();
			}
		}

		$data = $this->map(array_merge($data, $this->getChanges()));
		if (empty($data)) {
			Error::show('fault_save_data');
		}

		if (!$debug && $this->_relations) {
			Ocara::services()->transaction->begin();
		}

		Ocara::services()->transaction->push($this->_plugin);

		if ($condition) {
			$result = $this->_plugin->update($this->_tableName, $data, $condition, $debug);
			if (!$debug){
				$this->_relateSave();
				if($this->_selected && method_exists($this, '_afterUpdate')) {
					$this->event('_afterUpdate')->fire();
				}
			}
		} else {
			$result = $this->_plugin->insert($this->_tableName, $data, $debug);
			if (!$debug) {
				$this->_insertId = $this->_plugin->getInsertId();
				$this->_selectInsertRow($data);
				$this->_relateSave();
				if (method_exists($this, '_afterCreate')) {
					$this->event('_afterCreate')->fire();
				}
			}
		}

		$this->clearSql();

		if ($debug === DatabaseBase::DEBUG_RETURN) return $result;

		$result = $this->_plugin->errorExists() ? false : true;
		return $result;
	}

	/**
	 * 选择当前插入成功的记录
	 * @param $data
	 */
	public function _selectInsertRow($data)
	{
		$primaries = array();

		foreach ($this->_primaries as $field) {
			if (isset($data[$field])) {
				$primaries[] = $data[$field];
			} else {
				$primaries[] = $this->_insertId;
			}
		}

		$where = $this->_getPrimaryCondition($primaries);
		$this->findRow($where);
	}

	/**
	 * 获取最后插入的记录ID
	 * @return mixed
	 */
	public function getInsertId()
	{
		return $this->_insertId;
	}

	/**
	 * 预处理
	 * @param bool $prepare
	 */
	public function prepare($prepare = true)
	{
		$this->_plugin->is_prepare($prepare);
	}

	/**
	 * 保存数据（ORM模型）
	 * @param $debug
	 * @return mixed
	 */
	public function save($debug = false)
	{
		$data = $this->getProperty();

		$condition = array();
		foreach ($this->_primaries as $field) {
			if ($this->hasProperty($field)) {
				$condition[$field] = $this->getProperty($field);
			}
		}

		$result = $this->_save($data, $condition, $debug);
		return $result;
	}

	/**
	 * 新建记录
	 * @param array $data
	 * @param bool $debug
	 * @return bool
	 */
	public function create(array $data = array(), $debug = false)
	{
		$this->connect();

		$data = $this->_getSubmitData($data);
		$result = $this->_save($data, false, $debug);
		return $result;
	}

	/**
	 * 更新记录
	 * @param array $data
	 * @param bool $debug
	 * @return bool
	 * @throws \Ocara\Exception
	 */
	public function update(array $data = array(), $debug = false)
	{
		$condition = $this->_getCondition();
		if (empty($condition)) {
			Error::show('need_condition');
		}

		$data = $this->_getSubmitData($data);
		$result = $this->_save($data, $condition, $debug);
		return $result;
	}

	/**
	 * 获取数据
	 * @param $data
	 * @return array|null|string
	 */
	protected function _getSubmitData($data)
	{
		if (empty($data)) {
			$data = Request::getPost();
			if ($data) {
				$this->loadFields();
			}
		}

		return $data;
	}

	/**
	 * 删除记录
	 * @param bool $debug
	 * @return bool
	 * @throws \Ocara\Exception
	 */
	public function delete($debug = false)
	{
		$condition = $this->_getCondition();
		if (empty($condition)) {
			Error::show('need_condition');
		}

		Ocara::services()->transaction->push($this->_plugin);

		if (!$debug && $this->_selected && method_exists($this, '_beforeDelete')) {
			$this->event('_beforeDelete')->fire();
		}

		$result = $this->_plugin->delete($this->_tableName, $condition, $debug);
		if (!$debug
			&& !$this->_plugin->errorExists()
			&& $this->_selected
			&& method_exists($this, '_afterDelete')
		) {
			$this->event('_afterDelete')->fire();
		}

		if ($debug === DatabaseBase::DEBUG_RETURN) {
			return $result;
		}

		$this->clearSql();

		$result = $this->_plugin->errorExists() ? false : true;
		return $result;
	}

	/**
	 * 获取操作条件
	 * @return array|null
	 */
	private function _getCondition()
	{
		$this->connect();
		$condition = $this->_genWhere();

		return $condition;
	}

	/**
	 * 用SQL语句获取多条记录
	 * @param string $sql
	 * @param bool $debug
	 * @return bool
	 */
	public function query($sql, $debug = false)
	{
		if ($sql) {
			$sqlData = $this->_plugin->getSqlData($sql);
			return $this->connect(false)->query($sqlData, $debug);
		}

		return false;
	}

	/**
	 * 用SQL语句获取一条记录
	 * @param string $sql
	 * @param bool $debug
	 * @return bool
	 */
	public function queryRow($sql, $debug = false)
	{
		if ($sql) {
			$sqlData = $this->_plugin->getSqlData($sql);
			return $this->connect(false)->queryRow($sqlData, $debug);
		}

		return false;
	}

	/**
	 * 获取SQL
	 * @return array
	 */
	public function getSql()
	{
		return $this->_sql;
	}

	/**
	 * 设置SQL
	 * @param $sql
	 */
	public function setSql($sql)
	{
		$this->_sql = $sql;
	}

	/**
	 * 按主键选择一行记录
	 * @param string|array|number $primaryValues
	 * @param null $option
	 * @param bool $debug
	 * @return static
	 */
	public static function select($primaryValues, $option = null, $debug = false)
	{
		$model = new static();
		$condition = $model->_getPrimaryCondition($primaryValues);
		$model->findRow($condition, $option, $debug);

		return $model;
	}

	/**
	 * 按条件选择首行
	 * @param bool $condition
	 * @param null $option
	 * @param bool $debug
	 * @return $this|array|null
	 */
	public function findRow($condition = false, $option = null, $debug = false)
	{
		$this->clearData();

		if ($condition) {
			$this->where($condition);
		}

		if (!empty($this->_sql['option']['where'])) {
			$this->_selected['where'] = $this->_sql['option']['where'];
		}

		if (!empty($this->_sql['option']['mWhere'])) {
			$this->_selected['mWhere'] = $this->_sql['option']['mWhere'];
		}

		$data = $this->getRow(false, $option, $debug);
		if ($debug === DatabaseBase::DEBUG_RETURN) return $data;

		if ($data) {
			$this->data($data);
			return $this;
		}

		return null;
	}

	/**
	 * 选择多条记录
	 * @param $condition
	 * @param null $options
	 * @param bool $debug
	 * @return array|ObjectRecords
	 */
	public static function find($condition, $options = null, $debug = false)
	{
		$records = new ObjectRecords(self::getClass(), array($condition), $options, $debug);

		$times = isset($options['times']) ? $options['times'] : 0;
		$start = isset($options['start']) ? $options['start'] : 0;
		$rows = isset($options['rows']) ? $options['rows'] : 1;

		$records->setLimit($times, $start, $rows);

		return $records;
	}

	/**
	 * 获取主键条件
	 * @param $condition
	 * @return array
	 * @throws \Ocara\Exception
	 */
	private function _getPrimaryCondition($condition)
	{
		if (empty($this->_primaries)) {
			Error::show('no_primary');
		}

		if (ocEmpty($condition)) {
			Error::show('need_primary_value');
		}

		if (is_string($condition) || is_numeric($condition)) {
			$values = explode(',', trim($condition));
		} elseif (is_array($condition)) {
			$values = $condition;
		} else {
			Error::show('fault_primary_value_format');
		}

		if (count($this->_primaries) == count($values)) {
			$where = $this->map(array_combine($this->_primaries, $values));
		} else {
			Error::show('fault_primary_num');
		}

		return $where;
	}

	/**
	 * 查询多条记录
	 * @param null $condition
	 * @param null $option
	 * @param bool $debug
	 * @return array
	 */
	public function getAll($condition = null, $option = null, $debug = false)
	{
		return $this->_find($condition, $option, $debug, false);
	}

	/**
	 * 查询一条记录
	 * @param bool $condition
	 * @param bool $option
	 * @param bool $debug
	 * @return array
	 */
	public function getRow($condition = null, $option = null, $debug = false)
	{
		return $this->_find($condition, $option, $debug, true);
	}

	/**
	 * 获取某个字段值
	 * @param string $field
	 * @param bool $condition
	 * @param bool $debug
	 * @return array|null|string
	 */
	public function getValue($field, $condition = false, $debug = false)
	{
		$row = $this->getRow($condition, $field, $debug);

		if ($debug === DatabaseBase::DEBUG_RETURN) return $row;

		if (is_object($row)) {
			return property_exists($row, $field) ? $row->$field : null;
		}

		$row = (array)$row;
		return isset($row[$field]) ? $row[$field] : OC_EMPTY;
	}

	/**
	 * 查询总数
	 * @param bool $debug
	 * @return array|int
	 */
	public function getTotal($debug = false)
	{
		$queryRow = true;
		if ($this->_unions || !empty($this->_sql['option']['group'])) {
			$queryRow = false;
		}

		$result = $this->_find(false, false, $debug, $queryRow, true);

		if ($debug === DatabaseBase::DEBUG_RETURN) {
			return $result;
		}

		if ($result) {
			if (!$queryRow) {
				$result = reset($result);
			}
			return (integer)$result['total'];
		}

		return 0;
	}

	/**
	 * 查询数据
	 * @param string|array $condition
	 * @param string|array$option
	 * @param bool $debug
	 * @param bool $queryRow
	 * @param bool $count
	 * @return array
	 */
	private function _find($condition, $option, $debug, $queryRow, $count = false)
	{
		if ($condition) $this->where($condition);
		if ($queryRow) {
			if (!empty($this->_sql['option']['limit'])) {
				$this->_sql['option']['limit'][1] = 1;
			} else {
				$this->limit(1);
			}
		}

		if ($option) {
			if (ocScalar($option)) {
				$this->fields($option);
			} else {
				foreach ($option as $key => $value) {
					if (method_exists($this, $key)) {
						$value = (array)$value;
						call_user_func_array(array($this, $key), $value);
					}
				}
			}
		}

		$sql = $this->_genSelectSql($count);

		$cacheInfo = null;
		if (isset($this->_sql['cache']) && is_array($this->_sql['cache'])) {
			$cacheInfo = $this->_sql['cache'];
		}

		list($cacheConnect, $cacheRequired) = $cacheInfo;
		$ifCache = empty($debug) && $cacheConnect;

		if ($ifCache) {
			$encodeSql = md5($sql);
			$cacheObj  = Cache::connect($cacheConnect, $cacheRequired);
			$cacheData = $this->_getCacheData($sql, $encodeSql, $cacheObj, $cacheRequired);
			if ($cacheData) return $cacheData;
		}

		if ($queryRow) {
			$result = $this->_plugin->queryRow($sql, $debug, $count, $this->_unions);
		} else {
			$result = $this->_plugin->query($sql, $debug, true, true, false, $count, $this->_unions);
		}

		if ($debug === DatabaseBase::DEBUG_RETURN) {
			return $result;
		}

		if (!$count && ocGet('option.page', $this->_sql)) {
			$result = array('total' => $this->getTotal($debug), 'data'	=> $result);
		}

		$this->clearSql();

		if ($ifCache && is_object($cacheObj)) {
			$this->_saveCacheData($cacheObj, $sql, $encodeSql, $cacheRequired, $result);
		}

		return $result;
	}

	/**
	 * 连接数据库
	 * @param bool $master
	 * @return null
	 */
	public function connect($master = true)
	{
		$this->_plugin = null;

		if (!($master || ocGet('option.master', $this->_sql))) {
			if (!is_object($this->_slave)) {
				$this->_slave = DatabaseFactory::create($this->_server, false, false);
			}
			$this->_plugin = $this->_slave;
		}

		if (!is_object($this->_plugin)) {
			if (!is_object($this->_master)) {
				$this->_master = DatabaseFactory::create($this->_server);
			}
			$this->_plugin = $this->_master;
		}

		if ($this->_database) {
			$this->_plugin->selectDatabase($this->_database);
		}

		$this->_plugin->setDataType($this->_dataType);

		return $this->_plugin;
	}

	/**
	 * 获取缓存数据
	 * @param object $cacheObj
	 * @param string $sql
	 * @param string $sqlEncode
	 * @param bool $cacheRequired
	 * @return array|bool|\mix|mixed|null|\stdClass|string
	 */
	public function _getCacheData($cacheObj, $sql, $sqlEncode, $cacheRequired)
	{
		if (is_object($cacheObj)) {
			if ($callback = ocConfig('CALLBACK.model.query.get_cache_data', null)) {
				$params = array($cacheObj, $sql, $cacheRequired);
				if ($result = Call::run($callback, $params)) {
					return $result;
				}
			} else {
				if ($cacheData = $cacheObj->get($sqlEncode)) {
					return json_decode($cacheData);
				}
			}
		}

		return null;
	}

	/**
	 * 保存缓存数据
	 * @param object $cacheObj
	 * @param string $sql
	 * @param string $sqlEncode
	 * @param bool $cacheRequired
	 * @param array $result
	 */
	public function _saveCacheData($cacheObj, $sql, $sqlEncode, $cacheRequired, $result)
	{
		if ($callback = ocConfig('CALLBACK.model.query.save_cache_data', null)) {
			$params = array($cacheObj, $sql, $result, $cacheRequired);
			Call::run($callback, $params);
		} else {
			$cacheObj->set($sqlEncode, json_encode($result));
		}
	}

	/**
	 * 左联接
	 * @param string $class
	 * @param null $alias
	 * @param null $on
	 * @return $this|Database
	 */
	public function leftJoin($class, $alias = null, $on = null)
	{
		return $this->_join('left', $class, $alias, $on);
	}

	/**
	 * 右联接
	 * @param string $class
	 * @param null $alias
	 * @param null $on
	 * @return $this|Database
	 */
	public function rightJoin($class, $alias = null, $on = null)
	{
		return $this->_join('right', $class, $alias, $on);
	}

	/**
	 * 内全联接
	 * @param string $class
	 * @param null $alias
	 * @param null $on
	 * @return $this|Database
	 */
	public function innerJoin($class, $alias = null, $on = null)
	{
		return $this->_join('inner', $class, $alias, $on);
	}

	/**
	 * 解析on参数
	 * @param string $alias
	 * @param string $on
	 * @return mixed
	 */
	public function parseJoinOnSql($alias, $on)
	{
		if (is_array($on)) {
			$on = $this->_plugin->parseCondition($on, 'AND', '=', $alias);
		}

		return $on;
	}

	/**
	 * 解析fields参数
	 * @param string $alias
	 * @param array $fields
	 * @return string
	 */
	public function parseField($alias, $fields)
	{
		$_field = explode(',', $fields);

		foreach ($_field as $key => $value) {
			$value = explode('.', ltrim($value));
			$field = trim($value[count($value) - 1]);
			$_field[$key] = $this->_plugin->getFieldNameSql($field, $alias);
		}

		return implode(',', $_field);
	}

	/**
	 * 附加字段
	 * @param string|array $fields
	 * @param bool $alias
	 * @return $this
	 */
	public function fields($fields, $alias = false)
	{
		if ($fields) {
			$fields = array($alias, $fields);
			$this->_sql['option']['fields'][] = $fields;
		}

		return $this;
	}

	/**
	 * 附加联接关系
	 * @param string $on
	 * @param bool $alias
	 * @return $this
	 */
	private function _addOn($on, $alias = false)
	{
		$this->_sql['tables'][$alias]['on'] = $on;
		return $this;
	}

	/**
	 * 生成AND Between条件
	 * @param string $field
	 * @param string $value1
	 * @param string $value2
	 * @param bool $alias
	 * @param string $linkSign
	 * @return $this
	 */
	public function between($field, $value1, $value2, $alias = false, $linkSign = 'AND')
	{
		$where = array($alias, 'between', array($field, $value1, $value2), $linkSign);
		$this->_sql['option']['where'][] = $where;

		return $this;
	}

	/**
	 * 生成OR Between条件
	 * @param string $field
	 * @param string $value1
	 * @param string $value2
	 * @param bool $alias
	 * @return $this
	 */
	public function orBetween($field, $value1, $value2, $alias = false)
	{
		$this->between($field, $value1, $value2, $alias, 'OR');
		return $this;
	}

	/**
	 * 添加条件
	 * @param array $where
	 * @param bool $alias
	 * @param string $linkSign
	 * @return $this
	 */
	public function where($where, $alias = false, $linkSign = 'AND')
	{
		if (!ocEmpty($where)) {
			$where = array($alias, 'where', $where, $linkSign);
			$this->_sql['option']['where'][] = $where;
		}

		return $this;
	}

	/**
	 * 添加OR条件
	 * @param array|string|number $where
	 * @param bool $alias
	 * @return $this
	 */
	public function orWhere($where, $alias = false)
	{
		$this->where($where, $alias, 'OR');
		return $this;
	}

	/**
	 * 生成复杂条件
	 * @param string $operator
	 * @param string $field
	 * @param mixed $value
	 * @param null $alias
	 * @return $this
	 */
	public function cWhere($operator, $field, $value, $alias = null)
	{
		$this->complexWhere('where', $operator, $field, $value, $alias);
		return $this;
	}

	/**
	 * 生成复杂条件
	 * @param string $operator
	 * @param string $field
	 * @param mixed $value
	 * @param null $alias
	 * @param string $type
	 * @return $this
	 */
	public function complexWhere($type, $operator, $field, $value, $alias = null)
	{
		$signInfo = explode('/', $operator);

		if (isset($signInfo[1])) {
			list($linkSign, $operator) = $signInfo;
		} else {
			$linkSign = 'AND';
			$operator = $signInfo[0];
		}

		$linkSign = strtoupper($linkSign);
		$where = array($alias, 'cWhere', array($operator, $field, $value), $linkSign);
		$this->_sql['option'][$type][] = $where;

		return $this;
	}

	/**
	 * 更多条件
	 * @param string $where
	 * @param bool $link
	 * @return $this
	 */
	public function mWhere($where, $link = false)
	{
		$link = $link ? $link : 'AND';
		$this->_sql['option']['mWhere'][] = compact('where', 'link');
		return $this;
	}

	/**
	 * 尾部更多SQL语句
	 * @param string $sql
	 * @return $this
	 */
	public function more($sql)
	{
		$sql = (array)$sql;
		foreach ($sql as $value) {
			$this->_sql['option']['more'][] = $value;
		}
		return $this;
	}

	/**
	 * 分组
	 * @param string $groupBy
	 * @return $this
	 */
	public function groupBy($groupBy)
	{
		if ($groupBy) {
			$this->_sql['option']['group'] = $groupBy;
		}
		return $this;
	}

	/**
	 * AND分组条件
	 * @param array $having
	 * @param string $linkSign
	 * @return $this
	 */
	public function having($having, $linkSign = 'AND')
	{
		if (!ocEmpty($having)) {
			$having = array(false, 'where', $having, $linkSign);
			$this->_sql['option']['having'][] = $having;
		}

		return $this;
	}

	/**
	 * OR分组条件
	 * @param array $having
	 * @return $this
	 */
	public function orHaving($having)
	{
		$this->having($having, 'OR');
		return $this;
	}

	/**
	 * 生成复杂条件
	 * @param string $operator
	 * @param string $field
	 * @param string|int $value
	 * @return $this
	 */
	public function cHaving($operator, $field, $value)
	{
		$this->complexWhere('having', $operator, $field, $value, false);
		$this->cWhere($operator, $field, $value, false, 'having');
		return $this;
	}

	/**
	 * 附加排序
	 * @param string $orderBy
	 * @return $this
	 */
	public function orderBy($orderBy)
	{
		if ($orderBy) {
			$this->_sql['option']['order'] = $orderBy;
		}
		return $this;
	}

	/**
	 * 附加Limit
	 * @param int $offset
	 * @param int $rows
	 * @return $this
	 */
	public function limit($offset, $rows = 1)
	{
		if (func_num_args() < 2) {
			$rows = $offset;
			$offset = 0;
		}

		$this->_sql['option']['limit'] = array($offset, $rows);
		return $this;
	}

	/**
	 * 分页处理
	 * @param array $limitInfo
	 * @return Database
	 */
	public function page(array $limitInfo)
	{
		$this->_sql['option']['page'] = true;
		list($offset, $rows) = $limitInfo;
		return $this->limit($offset, $rows);
	}

	/**
	 * 绑定占位符参数
	 * @param string $name
	 * @param string $value
	 * @return $this
	 */
	public function bind($name, $value)
	{
		$this->_plugin->bind($name, $value);
		return $this;
	}

	/**
	 * * 设置统计字段
	 * @param string $field
	 * @return $this
	 */
	public function countField($field)
	{
		$this->_sql['countField'] = $field;
		return $this;
	}

	/**
	 * 设置字段别名转换映射
	 * @param array $tables
	 * @return array
	 */
	private function _getAliasFields($tables)
	{
		$unJoined = count($tables) <= 1;
		$transforms = array();

		if ($unJoined) {
			$map = self::getConfig('MAP');
			if ($map) {
				$transforms[$this->_alias] = $map;
			}
		} else {
			$transforms = array();
			foreach ($tables as $alias => $row) {
				if ($alias == $this->_alias) {
					if ($map = self::getConfig('MAP')) {
						$transforms[$this->_alias] = $map;
					}
				} elseif (isset($this->_joins[$alias])) {
					if ($map = self::getConfig('MAP', null, $row['class'])) {
						$transforms[$alias] = $map;
					}
				}
			}
		}

		return $transforms;
	}

	/**
	 * 是否默认字段
	 * @param $fields
	 * @return bool
	 */
	private function _isDefaultFields($fields)
	{
		$isDefault = false;
		if (empty($fields)) {
			$isDefault = true;
		} else {
			$exp = '/^\{\w+\}$/';
			if (is_array($fields) && count($fields) == 1) {
				if (isset($fields[1]) && preg_match($exp, $fields[1])) {
					$isDefault = true;
				}
			} elseif (is_string($fields)) {
				if (isset($fields) && preg_match($exp, $fields)) {
					$isDefault = true;
				}
			}
		}

		return $isDefault;
	}

	/**
	 * 生成查询Sql
	 * @param bool $count
	 * @return mixed
	 */
	private function _genSelectSql($count = false)
	{
		$option = ocGet('option', $this->_sql, array());
		$tables = ocGet('tables', $this->_sql, array());
		$unJoined = count($tables) <= 1;
		$from = $this->_getFromSql($tables, $unJoined);

		if ($count) {
			$countField = ocGet('countField', $this->_sql, null);
			$isGroup = !empty($option['group']);
			$fields = $this->_plugin->getCountSql($countField, 'total', $isGroup);
		} else {
			$aliasFields = $this->_getAliasFields($tables);
			if (!isset($option['fields']) OR $this->_isDefaultFields($option['fields'])) {
				$option['fields'][] = array($this->_alias, array_keys($this->getFields()));
			}
			$fields = $this->_getFieldsSql($option['fields'], $aliasFields, $unJoined);
		}

		$option['where'] = $this->_genWhere();
		if (isset($option['having'])) {
			$option['having'] = $this->_getWhereSql($option['having']);
		}

		if (isset($option['limit'])) {
			if ($count) {
				ocDel($option, 'limit');
			} else {
				$option['limit'] = $this->_plugin->getLimitSql($option['limit']);
			}
		}

		return $this->_plugin->getSelectSql($fields, $from, $option);
	}

	/**
	 * 生成条件数据
	 * @return mixed
	 */
	private function _genWhere()
	{
		$option = ocGet('option', $this->_sql, array());
		$where = array();

		if (empty($option['where'])) {
			if (isset($this->_selected['where'])) {
				$option['where'] = $this->_selected['where'];
			}
			if (isset($this->_selected['mWhere'])) {
				$option['mWhere'] = $this->_selected['mWhere'];
			}
		}

		if (!empty($option['where'])) {
			$option['where'] = $this->_getWhereSql($option['where']);
			$where[] = array('where' => $option['where'], 'link' => 'AND');
		}

		if (!empty($option['mWhere'])) {
			foreach ($option['mWhere'] as $row) {
				$row['where'] = $this->_plugin->parseCondition($row['where']);
				$where[] = $row;
			}
		}

		return $where ? $this->_plugin->getWhereSql($where) : OC_EMPTY;
	}

	/**
	 * 获取条件SQL语句
	 * @param array $data
	 * @return array
	 */
	private function _getWhereSql(array $data)
	{
		$where = array();

		foreach ($data as $key => $value) {
			list($alias, $whereType, $whereData, $linkSign) = $value;

			$condition = null;
			if ($whereType == 'where') {
				if (is_array($whereData)) {
					$whereData = $this->map($whereData);
				}
				if ($whereData) {
					$condition = $this->_plugin->parseCondition($whereData, 'AND', '=', $alias);
				}
			} elseif ($whereType == 'between') {
				$field = $this->mapField($whereData[0]);
				if($field) {
					$whereData[0] = $field;
					$whereData[] = $alias;
					$condition = call_user_func_array(array($this->_plugin, 'getBetweenSql'), $whereData);
				}
			} else {
				$condition = $this->_getComplexWhere($whereData, $alias);
			}
			if ($condition) {
				$where[] = array($linkSign, $condition);
			}
		}

		$where = $this->_plugin->linkWhere($where);
		$where = $this->_plugin->wrapWhere($where);

		return $where;
	}

	/**
	 * 获取字段列表
	 * @param array $data
	 * @param array $aliasFields
	 * @param $unJoined
	 * @return mixed
	 */
	private function _getFieldsSql($data, $aliasFields, $unJoined)
	{
		if (is_string($data)) {
			return $data;
		}

		$fields = array();
		$primaries = $this->_selected ? $this->_primaries: array();

		foreach ($data as $key => $value) {
			list($alias, $fieldData) = $value;
			if (is_string($fieldData)) {
				$fieldData = array_map('trim', (explode(',', $fieldData)));
			}
			$alias = $unJoined ? false : $alias;
			$fieldData = (array)$fieldData;
			$fields[] = $this->_plugin->getFieldsSql($fieldData, $aliasFields, $this->_alias, $alias);
		}

		$sql = $this->_plugin->combineFieldsSql($fields, $aliasFields, $unJoined, $this->_alias, $primaries);
		return $sql;
	}

	/**
	 * 生成数据表SQL
	 * @param string $tables
	 * @param bool $unJoined
	 * @return null|string
	 */
	private function _getFromSql($tables, $unJoined)
	{
		$from = OC_EMPTY;

		foreach ($tables as $alias => $param) {
			list($type, $fullname, $on, $class, $config) = array_fill(0, 5, null);
			extract($param);

			if (empty($fullname)) continue;
			if ($unJoined) $alias = false;
			if ($config) {
				$on = $this->getJoinOnSql($alias, $config);
			}

			$on = $this->parseJoinOnSql($alias, $on);
			$fullname = $this->_plugin->getTableFullname($fullname);
			$from = $from . $this->_plugin->getJoinSql($type, $fullname, $alias, $on);
		}

		return $from;
	}

	/**
	 * 获取关联链接条件
	 * @param string $alias
	 * @param array $config
	 * @return bool
	 */
	public function getJoinOnSql($alias, $config)
	{
		$joinOn = false;

		if ($config) {
			$foreignField = $this->_plugin->getFieldNameSql($config['foreignKey'], $alias);
			$primaryField = $this->_plugin->getFieldNameSql($config['primaryKey'], $this->_alias);
			$where = array($foreignField => ocSql($primaryField));
			$condition[] = array('AND', $this->_plugin->parseCondition($where, 'AND', null, $alias));
			if (is_array($config['condition'])) {
				foreach ($config['condition'] as $key => $value) {
					$sign = null;
					if (is_array($value)) {
						list($sign, $value) = $value;
					}
					$key = $this->_plugin->getFieldNameSql($key, $alias);
					$where = array($key => $value);
					$condition[] = array('AND', $this->_plugin->parseCondition($where, 'AND', $sign, $alias));
				}
			}
			$joinOn = $this->_plugin->linkWhere($condition);
		}

		return $joinOn;
	}

	/**
	 * 复杂条件
	 * @param array $data
	 * @param string $alias
	 * @return null
	 * @throws \Ocara\Exception
	 */
	private function _getComplexWhere(array $data, $alias)
	{
		$cond = null;
		list($sign, $field, $value) = $data;

		$sign = array_map('trim', explode(OC_DIR_SEP, $sign));
		if (!$sign) {
			Error::show('fault_cond_sign');
		}

		if (isset($sign[1])) {
			list($link, $sign) = $sign;
		} else {
			$sign = $sign[0];
			$link = 'AND';
		}

		$where = array($field => $value);
		$cond = $this->_plugin->parseCondition($where, $link, $sign, $alias);

		return $cond;
	}

	/**
	 * 获取表名
	 * @return mixed
	 */
	protected function getTable()
	{
		return $this->_table;
	}


	/**
	 * 合并查询（去除重复值）
	 * @param ModelBase $model
	 * @return $this
	 */
	public function union(\Ocara\ModelBase $model)
	{
		$this->_union($model, false);
		return $this;
	}

	/**
	 * 合并查询
	 * @param ModelBase $model
	 * @return $this
	 */
	public function unionAll(\Ocara\ModelBase $model)
	{
		$this->_union($model, true);
		return $this;
	}

	/**
	 * 合并查询
	 * @param $model
	 * @param bool $unionAll
	 */
	public function _Union($model, $unionAll = false)
	{
		$this->_unions[] = compact('model', 'unionAll');
	}

	/**
	 * 获取合并设置
	 * @return array
	 */
	public function getUnions()
	{
		return $this->_unions;
	}

	/**
	 * 获取全表名
	 * @param string $table
	 * @return mixed
	 */
	protected function _getTable($table)
	{
		return empty($table) ? $this->_tableName : $table;
	}

	/**
	 * 联接查询
	 * @param string $type
	 * @param string $class
	 * @param string $alias
	 * @param bool $on
	 * @return $this
	 */
	private function _join($type, $class, $alias, $on = false)
	{
		$config = array();
		$this->connect();

		if ($type == false) {
			$alias = $this->_alias;
			$fullname = $this->getTableName();
			$class = $this->_tag;
		} else {
			$config = $this->_getRelateConfig($class);
			if ($config) {
				$alias = $alias ? $alias : $class;
				$class = $config['class'];
				$model = $class::build();
			} else {
				$model = $class::build();
			}
			$fullname = $model->getTableName();
			$alias = $alias ? $alias : $fullname;
			$this->_joins[$alias] = $model;
		}

		$this->_sql['tables'][$alias] = compact('type', 'fullname', 'class', 'config');

		if ($config) {
			$on = false;
			$this->_addOn($on, $alias);
		} elseif ($on) {
			$this->_addOn($on, $alias);
		}

		return $this;
	}

	/**
	 * 获取关联模型
	 * @param string $key
	 */
	public function &__get($key)
	{
		if (isset(self::$_config[$this->_tag]['JOIN'][$key])) {
			if (!isset($this->_relations[$key])) {
				$this->_relations[$key] = $this->_relateFind($key);
			}
			return $this->_relations[$key];
		} else {
			return parent::__get($key);
		}
	}

	/**
	 * 获取关联模型
	 * @param string $key
	 * @param \Ocara\mxied $value
	 */
	public function __set($key, $value)
	{
		if (isset(self::$_config[$this->_tag]['JOIN'][$key])) {
			$this->_relations[$key] = $value;
		} else {
			parent::__set($key, $value);
			$this->_changes[] = $key;
		}
	}

	/**
	 * 关联模型查询
	 * @param $alias
	 * @return null|ObjectRecords
	 */
	private function _relateFind($alias)
	{
		$config = $this->_getRelateConfig($alias);
		$result = null;

		if ($config) {
			$where = array($config['foreignKey'] => $this->$config['primaryKey']);
			if (in_array($config['joinType'], array('one','manyOne'))) {
				$result = $config['class']::build()
					->where($where)
					->where($config['condition'])
					->findRow();
			} elseif (in_array($config['joinType'], array('oneMany','many'))) {
				$result = new ObjectRecords($config['class'], array($where, $config['condition']));
				$result->setLimit(0, 0, 1);
			}
		}

		return $result;
	}

	/**
	 * 关联模型数据保存
	 * @return bool
	 */
	private function _relateSave()
	{
		if (!$this->_relations) {
			return true;
		}

		foreach ($this->_relations as $key => $object) {
			$config = $this->_getRelateConfig($key);
			if ($config && $this->hasProperty($config['primaryKey'])) {
				$data = array();
				if (in_array($config['joinType'], array('one','manyOne')) && is_object($object)) {
					$data = array($object);
				} elseif (in_array($config['joinType'], array('oneMany','many'))) {
					if (is_object($object)) {
						$data = array($object);
					} elseif (is_array($object)) {
						$data = $object;
					}
				}
				foreach ($data as &$model) {
					if ($model->hasChanged() && is_object($model) && $model instanceof \Ocara\ModelBase) {
						$model->$config['foreignKey'] = $this->$config['primaryKey'];
						if ($config['condition']) {
							foreach ($config['condition'] as $field => $value) {
								$model->$field = $value;
							}
						}
						$model->save();
					}
				}
			}
		}

		Ocara::services()->transaction->commit();

		return true;
	}

	/**
	 * 获取关联配置
	 * @param string $key
	 * @return array
	 * @throws \Ocara\Exception
	 */
	private function _getRelateConfig($key)
	{
		if (!isset(self::$_config[$this->_tag]['JOIN'][$key])) {
			return array();
		}

		$config = self::$_config[$this->_tag]['JOIN'][$key];

		if (count($config) < 3) {
			Error::show('fault_relate_config');
		}

		list($joinType, $class, $joinOn) = $config;
		$condition = isset($config[3]) ? $config[3]: null;
		$joinOn = array_map('trim', explode(',', $joinOn));

		if (isset($joinOn[1])) {
			list($primaryKey, $foreignKey) = $joinOn;
		} else {
			$primaryKey = $foreignKey = reset($joinOn);
		}

		$config = compact('joinType', 'class', 'primaryKey', 'foreignKey', 'condition');

		return $config;
	}
}