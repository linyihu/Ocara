<?php
/*************************************************************************************************
 * -----------------------------------------------------------------------------------------------
 * Ocara开源框架   数据库模型类Database
 * Copyright (c) http://www.ocara.cn All rights reserved.
 * -----------------------------------------------------------------------------------------------
 * @author Lin YiHu <linyhtianwa@163.com>
 ************************************************************************************************/
namespace Ocara\Models;

use Ocara\Core\DriverBase;
use Ocara\Generators\Sql;
use \ReflectionObject;
use Ocara\Exceptions\Exception;
use Ocara\Core\CacheFactory;
use Ocara\Core\DatabaseFactory;
use Ocara\Core\DatabaseBase;
use Ocara\Core\ModelBase;
use Ocara\Iterators\Database\BatchQueryRecords;
use Ocara\Iterators\Database\EachQueryRecords;

defined('OC_PATH') or exit('Forbidden!');

abstract class DatabaseModel extends ModelBase
{

	/**
	 * @var @primary 主键字段列表
	 * @var $primaries 主键字段数组
	 */
	protected static $primary;
	protected static $table;
    protected static $entity;
    protected static $database;

    protected $plugin;
    protected $alias;
    protected $module;
    protected $connectName = 'defaults';

    protected $tag;
    protected $master;
    protected $slave;
    protected $databaseName;
    protected $tableName;
    protected $autoIncrementField;
    protected $isClear = true;

    protected $primaries = array();
    protected $sql = array();
    protected $fields = array();
    protected $joins = array();
    protected $unions = array();
    protected $relateShardingData = array();
    protected $relateShardingInfo = array();

    protected static $config = array();
    protected static $configPath = array();
    protected static $requirePrimary;

    /**
     * 初始化
     * DatabaseModel constructor.
     * @param array $data
     */
	public function __construct(array $data = array())
	{
		$this->initialize();
		if ($data) $this->data($data);
	}

	/**
	 * 初始化
	 */
	public function initialize()
	{
		if (self::$requirePrimary === null) {
			$required = ocConfig('MODEL_REQUIRE_PRIMARY', false);
			self::$requirePrimary = $required ? true : false;
		}

		if (self::$requirePrimary && !static::$primary) {
			ocService()->error->show('no_primaries');
		}

		$this->tag = self::getClass();
		$this->tableName = static::$table ?: lcfirst(self::getClassName());
        $this->databaseName = static::$database ?: null;
        $this->primaries = static::getPrimaries();

		if (method_exists($this, '__start')) $this->__start();
		if (method_exists($this, '__model')) $this->__model();

		return $this;
	}

    /**
     * 注册事件
     */
    public function registerEvents()
    {
        $this->bindEvents($this);
    }

	/**
	 * 获取Model标记
	 * @return string
	 */
	public function getTag()
	{
		return $this->tag;
	}

	/**
	 * 获取表名
	 * @return mixed
	 */
	public function getTableName()
	{
		return $this->tableName;
	}

    /**
     * 获取表的全名（包括前缀）
     * @return mixed
     * @throws Exception
     */
	public function getTableFullname()
	{
		return $this->connect()->getTableFullname($this->tableName);
	}

	/**
	 * 获取当前服务器
	 * @return mixed
	 */
	public function getConnectName()
	{
		return $this->connectName;
	}

	/**
	 * 获取当前数据库
	 * @return mixed
	 */
	public static function getDatabase()
	{
		return static::$database;
	}

    /**
     * 获取当前数据库名称
     * @return mixed
     */
	public function getDatabaseName()
    {
        return $this->databaseName;
    }

    /**
     * 获取主键
     * @return array
     */
	public static function getPrimaries()
    {
        return static::$primary ? explode(',', static::$primary) : array();
    }

    /**
     * 执行分库分表
     * @param array $data
     * @param null $relationName
     * @return $this
     */
	public function sharding(array $data = array(), $relationName = null)
	{
	    if (func_num_args() >= 2) {
	        if ($relationName) {
                $this->relateShardingInfo[$relationName] = $data;
            }
        } else {
            if (method_exists($this, '__sharding')) {
                $this->__sharding($data);
            }
        }

		return $this;
	}

    /**
     * 加载配置文件
     */
	public static function loadConfig()
	{
	    $class = self::getClass();

		if (empty(self::$config[$class])) {
		    $model = new static();
			self::$config[$class] = $model->getModelConfig();
		}
	}

    /**
     * 获取Model的配置
     * @return array|mixed
     */
	public function getModelConfig()
	{
        $paths = $this->getConfigPath();

        $modelConfig = array(
            'MAPS' => $this->fieldsMap() ? : array(),
            'RELATIONS' => $this->relations() ? : array(),
            'RULES' => $this->rules() ? : array(),
            'LANG' => array()
        );

        if (ocFileExists($paths['lang'])) {
            $lang = @include($paths['lang']);
            if ($lang && is_array($lang)) {
                $modelConfig['LANG'] = array_merge($modelConfig['LANG'], $lang);
            }
        }

		if ($paths['moduleLang'] && ocFileExists($paths['moduleLang'])) {
			$lang = @include($paths['moduleLang']);
			if ($lang && is_array($lang)) {
				$modelConfig['LANG'] = array_merge($modelConfig['LANG'], $lang);
			}
		}

		ksort($modelConfig);
		return $modelConfig;
	}

    /**
     * 数据表字段别名映射
     * @return array
     */
	public function fieldsMap()
    {
        return array();
    }

    /**
     * 数据表关联
     * @return array
     */
    public function relations()
    {
        return array();
    }

    /**
     * 字段验证配置
     * @return array
     */
    public function rules()
    {
        return array();
    }

    /**
     * 获取配置数据
     * @param string $key
     * @param string $field
     * @return array|bool|mixed|null
     */
	public static function getConfig($key = null, $field = null)
	{
        self::loadConfig();
        $tag = self::getClass();

		if (isset($key)) {
            $key = strtoupper($key);
			if ($field) {
				return ocGet(array($key, $field), self::$config[$tag]);
			}
			return ocGet($key, self::$config[$tag], array());
		}

		return self::$config[$tag];
	}

    /**
     * 获取配置文件路径
     * @return array|mixed
     */
	public function getConfigPath()
	{
	    $tag = $this->tag;

	    if (!empty(self::$configPath[$tag])) {
	        return self::$configPath[$tag];
        }

        $moduleLang = OC_EMPTY;
        $language = ocService()->app->getLanguage();

        $ref = new ReflectionObject($this);
        $filePath = ocCommPath($ref->getFileName());
        $file = basename($filePath);
        $dir = dirname($filePath) . OC_DIR_SEP;

        if ($this->module) {
            list($rootPath, $subDir) = ocSeparateDir($dir, '/privates/model/database/');
            $modulePath = OC_MODULE_PATH ? : ocPath('modules');
            $moduleLang = $modulePath . '/' . $this->module . '/privates/lang/' . $language . '/database/' . $subDir . $file;
        } else {
            list($rootPath, $subDir) = ocSeparateDir($dir, '/application/model/database/');
        }

        $subDir = rtrim($subDir, '/');
        $paths = array(
            'lang' => ocPath('lang', ocDir($language, 'database', $subDir) . $file),
            'fields' => ocPath('fields',  ocDir($subDir) . $file),
            'moduleLang' => $moduleLang
        );

        return self::$configPath[$tag] = $paths;
	}

    /**
     * 数据字段别名映射
     * @param array $data
     * @return array
     */
	public static function mapData(array $data)
	{
		$config = self::getConfig('MAPS');
		if (!$config) return $data;

		$result = array();
		foreach ($data as $key => $value) {
			if (isset($config[$key])) {
				$result[$config[$key]] = $value;
			} else {
				$result[$key] = $value;
			}
		}

		return $result;
	}

    /**
     * 字段别名映射
     * @param $field
     * @return mixed
     */
	public static function mapField($field)
    {
        $config = self::getConfig('MAPS');
        $result = isset($config[$field]) ? $config[$field] : $field;
        return $result;
    }

    /**
     * 获取当前数据库对象
     * @param bool $slave
     * @return mixed
     */
	public function db($slave = false)
	{
		$name = $slave ? 'slave' : 'plugin';
		if (is_object($this->$name)) {
			return $this->$name;
		}

		ocService()->error->show('null_database');
	}

	/**
	 * 切换数据库
	 * @param string $name
	 */
	public function setDatabase($name)
	{
		$this->databaseName = $name;
	}

	/**
	 * 切换数据表
	 * @param $name
	 * @param null $primary
	 */
	public function selectTable($name, $primary = null)
	{
		$this->tableName = $name;
        if ($primary) {
            $this->primaries = explode(',', $primary);
        }
	}

    /**
     * 从数据库获取数据表的字段
     * @param bool $cache
     * @return $this
     * @throws Exception
     */
	public function loadFields($cache = true)
	{
		if ($cache) {
			$this->fields = $this->getFieldsConfig();
		}

		if (!$this->fields) {
			$fieldsInfo = $this->connect()->getFields($this->tableName);
            $this->autoIncrementField = $fieldsInfo['autoIncrementField'];
            $this->fields = $fieldsInfo['list'];
		}

		return $this;
	}

    /**
     * 获取字段配置
     * @return array|mixed
     */
	public function getFieldsConfig()
	{
		$paths = $this->getConfigPath();
		$path = ocLowerFile($paths['fields']);

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
		if (empty($this->fields)) {
			$this->loadFields();
		}

		return $this->fields;
	}

    /**
     * 获取字段
     */
    public function getFieldsName()
    {
        return array_keys($this->getFields());
    }

    /**
     * 获取自增字段名
     */
    public function getAutoIncrementField()
    {
        return $this->autoIncrementField;
    }


    /**
     * 清理SQL
     * @param bool $isClear
     * @return $this
     */
	public function clearSql($isClear = true)
	{
	    if (func_num_args()) {
	        $this->isClear = $isClear ? true : false;
        } else {
	        if ($this->isClear) {
                $this->sql = array();
            }
        }
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
		$server = $server ? : DatabaseFactory::getDefaultServer();
		$this->sql['cache'] = array($server, $required);
		return $this;
	}

	/**
	 * 规定使用主库查询
	 */
	public function master()
	{
		$this->sql['option']['master'] = true;
		return $this;
	}

    /**
     * 保存记录
     * @param $data
     * @param $conditionSql
     * @return bool
     * @throws Exception
     */
    public function baseSave($data, $conditionSql = null)
    {
        $plugin = $this->connect();

        $conditionSql = $conditionSql ?: $this->getWhereSql($plugin);
        if (!$conditionSql) {
            ocService()->error->show('need_condition');
        }

        $data = $this->filterData($data);
        $this->loadFields();

        if (empty($data)) {
            ocService()->error->show('fault_save_data');
        }

        if ($conditionSql) {
            $this->pushTransaction();
            $result = $plugin->update($this->tableName, $data, $conditionSql);
        } else {
            $autoIncrementField = $this->getAutoIncrementField();
            if (!in_array($autoIncrementField, $this->primaries)) {
                if (array_diff_key($this->primaries, array_keys($data))) {
                    ocService()->error->show('need_create_primary_data');
                }
            }
            $this->pushTransaction();
            $result = $plugin->insert($this->tableName, $data);
        }

        $this->clearSql();
        return $result;
    }

	/**
	 * 预处理
	 * @param bool $prepare
	 */
	public function prepare($prepare = true)
	{
		$this->plugin()->is_prepare($prepare);
	}

    /**
     * 推入事务池中
     */
	public function pushTransaction()
    {
        ocService()->transaction->push($this->plugin());
    }

    /**
     * 新建记录
     * @param array $data
     * @return mixed
     */
	public function create(array $data)
	{
	    $entityClass = $this->getEntityClass();
        $entity = new $entityClass();
        $result = $entity->create($data);
		return $result;
	}

    /**
     * 批量更新记录
     * @param array $data
     * @param int $batchLimit
     * @throws Exception
     */
	public function update(array $data, $batchLimit = 1000)
	{
        $plugin = $this->connect();
        $batchLimit = $batchLimit ?: 1000;
        $conditionSql = $this->getWhereSql($plugin);

		if ($batchLimit) {
            $dataType = $this->getDataType();
            if (!$dataType || in_array($dataType, array(DriverBase::DATA_TYPE_ARRAY, DriverBase::DATA_TYPE_OBJECT))) {
                $this->asEntity();
            }

            $batchData = $this->batch($batchLimit);

            foreach ($batchData as $entityList) {
                foreach ($entityList as $entity) {
                    $entity->data($data);
                    $entity->update();
                }
            }
        } else {
		    $this->baseSave($data, $conditionSql);
        }
	}

    /**
     * 批量删除记录
     * @param int $batchLimit
     * @throws Exception
     */
    public function delete($batchLimit = 1000)
    {
        $plugin = $this->connect();
        $batchLimit = $batchLimit ?: 1000;
        $conditionSql = $this->getWhereSql($plugin);

        if ($batchLimit) {
            $dataType = $this->getDataType();
            if (!$dataType || in_array($dataType, array(DriverBase::DATA_TYPE_ARRAY, DriverBase::DATA_TYPE_OBJECT))) {
                $this->asEntity();
            }

            $batchData = $this->batch($batchLimit);

            foreach ($batchData as $entityList) {
                foreach ($entityList as $entity) {
                    $entity->delete();
                }
            }
        } else {
            $this->baseDelete($conditionSql);
        }
    }

    /**
     * 删除记录
     * @param $conditionSql
     * @return mixed
     * @throws Exception
     */
	public function baseDelete($conditionSql = null)
	{
        $plugin = $this->connect();
        $conditionSql = $conditionSql ?: $this->getWhereSql($plugin);

        if (!$conditionSql) {
            ocService()->error->show('need_condition');
        }

        $this->pushTransaction();
		$result = $plugin->delete($this->tableName, $conditionSql);

		$this->clearSql();
		return $result;
	}

    /**
     * 用SQL语句获取多条记录
     * @param $sql
     * @return bool
     * @throws Exception
     */
	public function query($sql)
	{
        $plugin = $this->connect();

		if ($sql) {
			$sqlData = $plugin->getSqlData($sql);
			$dataType = $this->getDataType() ?: DriverBase::DATA_TYPE_ARRAY;
			return $plugin->query($sqlData, false, array(), $dataType);
		}

		return false;
	}

    /**
     * 用SQL语句获取一条记录
     * @param $sql
     * @return bool
     * @throws Exception
     */
	public function queryRow($sql)
	{
        $plugin = $this->connect();

		if ($sql) {
			$sqlData = $plugin->getSqlData($sql);
            $dataType = $this->getDataType() ?: DriverBase::DATA_TYPE_ARRAY;
			return $plugin->query($sqlData, false, array(), $dataType);
		}

		return false;
	}

	/**
	 * 获取SQL
	 * @return array
	 */
	public function getSql()
	{
		return $this->sql;
	}

    /**
     * 设置SQL
     * @param $sql
     * @return $this
     */
	public function setSql($sql)
	{
		$this->sql = $sql;
		return $this;
	}

    /**
     * 默认查询字段列表
     * @return $this
     */
	public function defaultFields()
    {
        if (method_exists($this, '__fields')) {
            $fields = $this->__fields();
            if ($fields) {
                $this->fields($fields);
            }
        }
        return $this;
    }

    /**
     * 默认查询条件
     * @return $this
     */
    public function defaultCondition()
    {
        if (method_exists($this, '__condition')) {
            $where = $this->__condition();
            if ($where) {
                $this->where($where);
            }
        }
        return $this;
    }

    /**
     * 按条件选择首行
     * @param bool $condition
     * @param null $options
     * @return $this|array|null
     * @throws Exception
     */
	public function selectOne($condition = false, $options = null)
	{
        $result = $this
            ->asEntity()
            ->baseFind($condition, $options, true);
        return $result;
	}

    /**
     * 选择多条记录
     * @param null $condition
     * @param null $options
     * @return array
     * @throws Exception
     */
	public function selectAll($condition = null, $options = null)
	{
        $records = $this
            ->asEntity()
            ->baseFind($condition, $options, false);
		return $records;
	}

    /**
     * 返回对象
     * @return $this
     */
    public function asArray()
    {
        return $this->setDataType(DriverBase::DATA_TYPE_ARRAY);
    }

    /**
     * 返回对象
     * @return $this
     */
	public function asObject()
    {
        return $this->setDataType(DriverBase::DATA_TYPE_OBJECT);
    }

    /**
     * 返回实体对象
     * @param null $entityClass
     * @return $this
     */
    public function asEntity($entityClass = null)
    {
        if (empty($entityClass)) {
            $entityClass = self::getDefaultEntityClass();
        }
        return $this->setDataType($entityClass);
    }

    /**
     * 设置返回数据类型
     * @param $dataType
     * @return $this
     */
    public function setDataType($dataType)
    {
        $this->sql['option']['dataType'] = $dataType;
        return $this;
    }

    /**
     * 获取当前返回数据类型
     * @return array|bool|mixed|null
     */
    public function getDataType()
    {
        return ocGet(array('option', 'dataType'), $this->sql, null);
    }

    /**
     * 选择多条记录
     * @param $batchLimit
     * @param int $totalLimit
     * @return BatchQueryRecords
     */
    public function batch($batchLimit, $totalLimit = 0)
    {
        $sql = $this->sql ? : array();
        $model = new static();

        $model->setSql($sql);
        $model->clearSql(false);
        $this->clearSql();

        $records = new BatchQueryRecords($model, $batchLimit, $totalLimit);
        return $records;
    }

    /**
     * 选择多条记录
     * @return EachQueryRecords
     */
    public function each()
    {
        $sql = $this->sql ? : array();
        $model = new static();

        $model->setSql($sql);
        $model->clearSql(false);
        $this->clearSql();

        $records = new EachQueryRecords($model);
        return $records;
    }

    /**
     * 获取默认实体类
     * @return string
     */
    public static function getDefaultEntityClass()
    {
        if (empty(static::$entity)) {
            ocService()->error->show('need_entity_class');
        }
        return static::$entity;
    }

    /**
     * 获取实体类
     * @return bool
     */
    public function getEntityClass()
    {
        $entityClass = OC_EMPTY;
        $dataType = $this->getDataType();

        if (!empty($dataType)) {
            $simpleDataTypes = array(DriverBase::DATA_TYPE_ARRAY, DriverBase::DATA_TYPE_OBJECT);
            if (!in_array($dataType, $simpleDataTypes)) {
                $entityClass = $dataType;
            }
        }

        if (!$entityClass) {
            $entityClass = self::getDefaultEntityClass();
        }

        return $entityClass;
    }

    /**
     * 查询多条记录
     * @param mixed $condition
     * @param mixed $option
     * @return array
     * @throws Exception
     */
	public function getAll($condition = null, $option = null)
	{
		return $this->baseFind($condition, $option, false, false);
	}

    /**
     * 查询一条记录
     * @param mixed $condition
     * @param mixed $option
     * @return array
     * @throws Exception
     */
	public function getRow($condition = null, $option = null)
	{
		return $this->baseFind($condition, $option, true, false);
	}

    /**
     * 获取某个字段值
     * @param string $field
     * @param bool $condition
     * @return array|mixed|string|null
     * @throws Exception
     */
	public function getValue($field, $condition = false)
	{
		$row = $this->getRow($condition, $field);

		if (is_object($row)) {
			return property_exists($row, $field) ? $row->$field : null;
		}

		$row = (array)$row;
		$result = isset($row[$field]) ? $row[$field] : OC_EMPTY;
		return $result;
	}

    /**
     * 查询总数
     * @return array|int|mixed
     * @throws Exception
     */
	public function getTotal()
	{
		$queryRow = true;
		if ($this->unions || !empty($this->sql['option']['group'])) {
			$queryRow = false;
		}

		$result = $this->baseFind(false, false, $queryRow, true);

		if ($result) {
			if (!$queryRow) {
				$result = reset($result);
			}
			return (integer)$result['total'];
		}

		return 0;
	}

    /**
     * 推入SQL选项
     * @param $condition
     * @param $option
     * @param $queryRow
     */
	public function pushSql($condition, $option, $queryRow)
    {
        if ($condition) $this->where($condition);

        if ($queryRow) {
            if (!empty($this->sql['option']['limit'])) {
                $this->sql['option']['limit'][1] = 1;
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
    }

    /**
     * 获取别名
     * @return mixed|string
     */
    public function getAlias()
    {
        return !empty($this->sql['alias']) ? $this->sql['alias'] : ($this->alias ?: 'a');
    }

    /**
     * 查询数据
     * @param mixed $condition
     * @param mixed $option
     * @param bool $queryRow
     * @param bool $count
     * @param null $dataType
     * @return array
     * @throws Exception
     */
    protected function baseFind($condition, $option, $queryRow, $count = false, $dataType = null)
	{
        $plugin = $this->connect(false);

	    $this->pushSql($condition, $option, $queryRow);
        $this->setJoin(null, $this->tag, $this->getAlias());

        $sql = $this->getSelectSql($plugin, $count);
        $dataType = $dataType ? : ($this->getDataType() ?: DriverBase::DATA_TYPE_ARRAY);

		if ($queryRow) {
            $result = $plugin->queryRow($sql, $count, $this->unions, $dataType);
		} else {
            $result = $plugin->query($sql, $count, $this->unions, $dataType);
		}

		if (!$count && !$queryRow && $this->isPage()) {
			$result = array('total' => $this->getTotal(), 'data'	=> $result);
		}

		$this->clearSql();
		return $result;
	}

    /**
     * 获取SQL生成器
     * @param $plugin
     * @return Sql
     */
	public function getSqlGenerator($plugin)
    {
        $generator = new Sql($plugin, $this->databaseName);

        $generator->setDefaultAlias($this->alias);
        $generator->setSql($this->sql);
        $generator->setFields($this->getFields());
        $generator->setMaps(static::getConfig('MAPS'));

        return $generator;
    }

    /**
     * 生成Select语句
     * @param $plugin
     * @param $count
     * @return array
     */
	public function getSelectSql($plugin, $count)
    {
        $generator = $this->getSqlGenerator($plugin);
        $sql = $generator->genSelectSql($count);
        $generator = null;
        unset($generator);
        return $sql;
    }

    /**
     * 生成Where语句
     * @param $plugin
     * @return bool|string
     */
    public function getWhereSql($plugin)
    {
        $generator = $this->getSqlGenerator($plugin);
        $sql = $generator->genWhereSql();
        $generator = null;
        unset($generator);
        return $sql;
    }

    /**
     * 是否分页
     * @return bool
     */
	public function isPage()
    {
        return ocGet(array('option', 'page'), $this->sql) ? true : false;
    }

    /**
     * 连接数据库
     * @param bool $master
     * @return mixed|null
     * @throws Exception
     */
	public function connect($master = true)
	{
        $plugin = $this->plugin(false);

        if (!$plugin) {
            if (!($master || ocGet(array('option', 'master'), $this->sql))) {
                if (!is_object($this->slave)) {
                    $this->slave = DatabaseFactory::create($this->connectName, false, false);
                }
                $plugin = $this->setPlugin($this->slave);
            }

            if (!is_object($plugin)) {
                if (!is_object($this->master)) {
                    $this->master = DatabaseFactory::create($this->connectName);
                }
                $plugin = $this->setPlugin($this->master);
            }
        }

        if (!$plugin->isSelectedDatabase()) {
            $plugin->selectDatabase($this->databaseName);
        }

		return $plugin;
	}

    /**
     * 左联接
     * @param string $class
     * @param string $alias
     * @param string $on
     * @return DatabaseModel
     */
	public function leftJoin($class, $alias = null, $on = null)
	{
		return $this->setJoin('left', $class, $alias, $on);
	}

    /**
     * 右联接
     * @param string $class
     * @param string $alias
     * @param string $on
     * @return DatabaseModel
     */
	public function rightJoin($class, $alias = null, $on = null)
	{
		return $this->setJoin('right', $class, $alias, $on);
	}

    /**
     * 内全联接
     * @param string $class
     * @param string $alias
     * @param string $on
     * @return DatabaseModel
     */
	public function innerJoin($class, $alias = null, $on = null)
	{
		return $this->setJoin('inner', $class, $alias, $on);
	}

    /**
     * 设置别名
     * @param $alias
     * @return $this
     */
	public function alias($alias)
    {
        $this->sql['alias'] = $alias;
        return $this;
    }

	/**
	 * 附加字段
	 * @param string|array $fields
	 * @param string $alias
	 * @return $this
	 */
	public function fields($fields, $alias = null)
	{
		if ($fields) {
			$fields = array($alias, $fields);
			$this->sql['option']['fields'][] = $fields;
		}

		return $this;
	}

	/**
	 * 附加联接关系
	 * @param string $on
	 * @param string $alias
	 * @return $this
	 */
    protected function addOn($on, $alias = null)
	{
		$this->sql['tables'][$alias]['on'] = $on;
		return $this;
	}

	/**
	 * 生成AND Between条件
	 * @param string $field
	 * @param string $value1
	 * @param string $value2
	 * @param string $alias
	 * @return $this
	 */
	public function between($field, $value1, $value2, $alias = null)
	{
		$where = array($alias, 'between', array($field, $value1, $value2), 'AND');
		$this->sql['option']['where'][] = $where;

		return $this;
	}

	/**
	 * 生成OR Between条件
	 * @param string $field
	 * @param string $value1
	 * @param string $value2
	 * @param string $alias
	 * @return $this
	 */
	public function orBetween($field, $value1, $value2, $alias = null)
	{
        $where = array($alias, 'between', array($field, $value1, $value2), 'OR');
        $this->sql['option']['where'][] = $where;

        return $this;
	}

    /**
     * 添加条件
     * @param $where
     * @param null $signOrAlias
     * @param null $value
     * @param null $alias
     * @return $this
     */
	public function where($where, $signOrAlias = null, $value = null, $alias = null)
	{
	    if (func_num_args() < 3) {
	        $alias = $signOrAlias;
            if (!ocEmpty($where)) {
                $where = array($alias, 'where', $where, 'AND');
                $this->sql['option']['where'][] = $where;
            }
        } else {
	        $sign = 'AND/' . $signOrAlias;
            $this->complexWhere('where', $sign, $where, $value, $alias);
        }

		return $this;
	}

    /**
     * 添加OR条件
     * @param $where
     * @param null $signOrAlias
     * @param null $value
     * @param null $alias
     * @return $this
     */
	public function orWhere($where, $signOrAlias = null, $value = null, $alias = null)
	{
        if (func_num_args() < 3) {
            $alias = $signOrAlias;
            if (!ocEmpty($where)) {
                $where = array($alias, 'where', $where, 'OR');
                $this->sql['option']['where'][] = $where;
            }
        } else {
            $sign = 'OR/' . $signOrAlias;
            $this->complexWhere('where', $sign, $where, $value, $alias);
        }

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
		$this->sql['option'][$type][] = $where;

		return $this;
	}

	/**
	 * 更多条件
	 * @param string $where
	 * @param string $link
	 * @return $this
	 */
	public function moreWhere($where, $link = null)
	{
		$link = $link ? : 'AND';
		$this->sql['option']['moreWhere'][] = compact('where', 'link');
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
			$this->sql['option']['more'][] = $value;
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
			$this->sql['option']['group'] = $groupBy;
		}
		return $this;
	}

    /**
     * AND分组条件
     * @param $where
     * @param null $sign
     * @param null $value
     * @return $this
     */
	public function having($where, $sign = null, $value = null)
	{
        if (func_num_args() < 3) {
            if (!ocEmpty($where)) {
                $where = array(false, 'where', $where, 'AND');
                $this->sql['option']['having'][] = $where;
            }
        } else {
            $sign = 'AND/' . $sign;
            $this->complexWhere('having', $sign, $where, $value, false);
        }

		return $this;
	}

    /**
     * OR分组条件
     * @param $where
     * @param null $sign
     * @param null $value
     * @return $this
     */
	public function orHaving($where, $sign = null, $value = null)
	{
        if (func_num_args() < 3) {
            if (!ocEmpty($where)) {
                $where = array(false, 'where', $where, 'OR');
                $this->sql['option']['having'][] = $where;
            }
        } else {
            $sign = 'OR/' . $sign;
            $this->complexWhere('having', $sign, $where, $value, false);
        }

		return $this;
	}

	/**
	 * 添加排序
	 * @param string $orderBy
	 * @return $this
	 */
	public function orderBy($orderBy)
	{
		if ($orderBy) {
			$this->sql['option']['order'] = $orderBy;
		}
		return $this;
	}

    /**
     * 添加union排序
     * @param string $orderBy
     * @return $this
     */
    public function unionOrderBy($orderBy)
    {
        if ($orderBy) {
            $this->unions['option']['order'] = $orderBy;
        }
        return $this;
    }

	/**
	 * 添加limit
	 * @param int $offset
	 * @param int $rows
	 * @return $this
	 */
	public function limit($offset, $rows = null)
	{
		if (isset($rows)) {
		    $rows = $rows ? : 1;
		} else {
            $rows = $offset;
            $offset = 0;
        }

		$this->sql['option']['limit'] = array($offset, $rows);
		return $this;
	}

    /**
     * 添加union limit
     * @param int $offset
     * @param int $rows
     * @return $this
     */
    public function unionLimit($offset, $rows = null)
    {
        if (isset($rows)) {
            $rows = $rows ? : 1;
        } else {
            $rows = $offset;
            $offset = 0;
        }

        $this->unions['option']['limit'] = array($offset, $rows);
        return $this;
    }

    /**
     * 分页处理
     * @param array $limitInfo
     * @return DatabaseModel
     */
	public function page(array $limitInfo)
	{
		$this->sql['option']['page'] = true;
		return $this->limit($limitInfo['offset'], $limitInfo['rows']);
	}

	/**
	 * 绑定占位符参数
	 * @param string $name
	 * @param string $value
	 * @return $this
	 */
	public function bind($name, $value)
	{
	    $this->sql['binds'][$name] = $value;
		return $this;
	}

	/**
	 * * 设置统计字段
	 * @param string $field
	 * @return $this
	 */
	public function countField($field)
	{
		$this->sql['countField'] = $field;
		return $this;
	}

    /**
     * 获取最后执行的SQL
     */
    public function getLastSql()
    {
        return $this->connect()->getLastSql();
    }

	/**
	 * 获取表名
	 * @return mixed
	 */
	public static function getTable()
	{
		return static::$table;
	}

	/**
	 * 合并查询（去除重复值）
	 * @param ModelBase $model
	 * @return $this
	 */
	public function union(ModelBase $model)
	{
		$this->baseUnion($model, false);
		return $this;
	}

	/**
	 * 合并查询
	 * @param ModelBase $model
	 * @return $this
	 */
	public function unionAll(ModelBase $model)
	{
		$this->baseUnion($model, true);
		return $this;
	}

	/**
	 * 合并查询
	 * @param $model
	 * @param bool $unionAll
	 */
	public function baseUnion($model, $unionAll = false)
	{
		$this->unions['models'][] = compact('model', 'unionAll');
	}

	/**
	 * 获取合并设置
	 * @return array
	 */
	public function getUnions()
	{
		return $this->unions;
	}

    /**
     * 联接查询
     * @param $type
     * @param $class
     * @param $alias
     * @param bool $on
     * @return $this
     */
    protected function setJoin($type, $class, $alias, $on = false)
	{
		$config = array();

		if ($type == false) {
            $fullName = $this->getTableName();
            $alias = $alias ?: $fullName;
            $class = $this->tag;
            $tables = array($alias => compact('type', 'fullName', 'class', 'config'));
            if (!empty($this->sql['tables'])) {
                $this->sql['tables'] = array_merge($tables, $this->sql['tables']);
            } else {
                $this->sql['tables'] = $tables;
            }
		} else {
            $shardingData = array();
            $relateShardingInfo = $this->getRelateShardingInfo($class);
            if ($relateShardingInfo) {
                list($class, $shardingData) = $relateShardingInfo;
            }
			$config = $this->getRelateConfig($class);
			if ($config) {
				$alias = $alias ? : $class;
				$class = $config['class'];
			}
            $model = $class::build();
			if ($shardingData) {
                $model->sharding($shardingData);
            }
            $fullName = $model->getTableName();
			$alias = $alias ?: $fullName;
			$this->joins[$alias] = $model;
            $this->sql['tables'][$alias] = compact('type', 'fullName', 'class', 'config');
		}

		if ($on) {
            $this->addOn($on, $alias);
        } elseif($config) {
            $this->addOn(null, $alias);
        }

		return $this;
	}

    /**
     * 通过关键字获取分库分表信息
     * @param $keyword
     * @return string
     */
	protected function getRelateShardingInfo($keyword)
    {
        $relationShardingInfo = array();

        if (preg_match('/^[\{](\w+)[\}]$/i', $keyword, $matches)) {
            $relationAlias = $matches[1];
            if (array_key_exists($relationAlias, $this->relateShardingInfo)) {
                $relationShardingInfo = $this->relateShardingInfo[$relationAlias];
            } else {
                if (array_key_exists($relationAlias, $this->relateShardingData)) {
                    $config = $this->getRelateConfig($relationAlias);
                    if ($config) {
                        $shardingData = $this->relateShardingData[$relationAlias];
                        $relationShardingInfo = array($config['class'], $shardingData);
                        $this->relateShardingInfo[$relationAlias] = $relationShardingInfo;
                    }
                }
            }
        }

        return $relationShardingInfo;
    }

    /**
     * 获取关联配置
     * @param $key
     * @return array
     */
    public function getRelateConfig($key)
	{
        $relations = $this->getConfig('RELATIONS');

		if (empty($relations[$key])) {
			return array();
		}

		$config = $relations[$key];
		if (count($config) < 3) {
			ocService()->error->show('fault_relate_config');
		}

		list($joinType, $class, $joinOn) = $config;
		$condition = isset($config[3]) ? $config[3]: null;

		if (!is_array($joinOn)) {
            $joinOn = explode(',', $joinOn);
        }

		$joinOn = array_map('trim', $joinOn);

		if (isset($joinOn[1])) {
			list($primaryKey, $foreignKey) = $joinOn;
		} else {
			$primaryKey = $foreignKey = reset($joinOn);
		}

		$config = compact('joinType', 'class', 'primaryKey', 'foreignKey', 'condition');
		return $config;
	}

    /**
     * 魔术方法-调用未定义的静态方法时
     * @param string $name
     * @param array $params
     * @return mixed
     */
    public static function __callStatic($name, $params)
    {
        $regExp = '/^((findRowBy)|(findBy)|(getRowBy)|(getAllBy))(\w+)$/';

        if (preg_match($regExp, $name, $matches)) {
            $method = $matches[1];
            $fieldName = lcfirst($matches[6]);
            return self::queryDynamic($method, $fieldName, $params);
        }

        return parent::__callStatic($name, $params);
    }

    /**
     * 动态查询
     * @param $method
     * @param $fieldName
     * @param $params
     * @return mixed
     */
    protected static function queryDynamic($method, $fieldName, array $params = array())
    {
        if (empty($params)) {
            ocService()->error->show('need_find_value');
        }

        $value = reset($params);
        if (!ocSimple($value)) {
            ocService()->error->show('fault_find_value');
        }

        $model = new static();
        $fields = $model->getFields();

        if (array_key_exists($fieldName, $fields)){
            $fieldName = ocHumpToLine($fieldName);
            if (array_key_exists($fieldName, $fields)) {
                $model->where(array($fieldName => $value));
                return $model->$method();
            }
        }

        ocService()->error->show('not_exists_find_field');
    }
}
