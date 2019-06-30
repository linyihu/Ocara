<?php
namespace Ocara\Models;

use Ocara\Core\BaseEntity;
use Ocara\Exceptions\Exception;
use Ocara\Iterators\Database\ObjectRecords;

defined('OC_PATH') or exit('Forbidden!');

abstract class DatabaseEntity extends BaseEntity
{
    private $selected = array();
    private $changes = array();
    private $oldData = array();
    private $relations = array();

    /**
     * @var int $insertId
     */
    private $insertId;

    /**
     * @var string $modelClass
     */
    private $modelClass;

    const EVENT_BEFORE_CREATE = 'beforeCreate';
    const EVENT_AFTER_CREATE = 'afterCreate';
    const EVENT_BEFORE_UPDATE = 'beforeUpdate';
    const EVENT_AFTER_UPDATE = 'afterUpdate';
    const EVENT_BEFORE_DELETE = 'beforeDelete';
    const EVENT_AFTER_DELETE = 'afterDelete';

    /**
     * DatabaseEntity constructor.
     */
    public function __construct()
    {
        $this->setPlugin(new $this->modelClass());
    }

    /**
     * 加载数据
     * @param array $data
     * @return $this
     */
    public function data(array $data = array())
    {
        $data = $this->plugin()->getSubmitData($data);
        if ($data) {
            $this->setProperty($this->plugin()->filterData($data));
        }

        return $this;
    }

    /**
     * 清除数据
     */
    public function clearData()
    {
        $this->selected = array();
        $this->clearProperties($this->plugin()->getFieldsName());
        return $this;
    }

    /**
     * 清除查询设置和数据
     * @return $this
     */
    public function clearAll()
    {
        parent::clearAll();
        $this->clearData();
        return $this;
    }

    /**
     * 获取旧值
     * @param null $key
     * @return array|mixed
     */
    public function getOld($key = null)
    {
        if (func_num_args()) {
            if (array_key_exists($key, $this->oldData)){
                return $this->oldData[$key];
            }
            ocService()->error->show('no_old_field');
        }
        return $this->oldData;
    }

    /**
     * 获取新值
     * @param null $key
     * @return array|mixed
     */
    public function getChanged($key = null)
    {
        if (func_num_args()) {
            if (in_array($key, $this->changes)) {
                return $this->changes[$key];
            }
            ocService()->error->show('no_changed_field');
        }

        $changes = array_fill_keys($this->changes, null);
        return array_intersect_key($this->toArray(), $changes);
    }

    /**
     * 是否赋新值
     * @param string $key
     * @return bool
     */
    public function hasChanged($key = null)
    {
        if (func_num_args()) {
            return in_array($key, $this->changes);
        }
        return !empty($this->changes);
    }

    /**
     * 是否有旧值
     * @param string $key
     * @return bool
     */
    public function hasOld($key = null)
    {
        if (func_num_args()) {
            return in_array($key, $this->oldData);
        }
        return !empty($this->oldData);
    }

    /**
     * 选择记录
     * @param $values
     * @param null $options
     * @param bool $debug
     * @return array|DatabaseModel|null
     */
    public static function select($values, $options = null, $debug = false)
    {
        $model = new static();
        $condition = $model->getPrimaryCondition($values);

        return $model->asEntity(self::getClass())->findRow($condition, $options, $debug);
    }

    /**
     * 新建
     * @param array $data
     * @param bool $debug
     * @return bool
     * @throws Exception
     */
    public function create(array $data = array(), $debug = false)
    {
        if (!$debug && $this->relations) {
            ocService()->transaction->begin();
        }

        $this->fire(self::EVENT_BEFORE_CREATE);

        if ($data) {
            $this->setProperty($data);
        }

        $result = $this->plugin()->create($this->toArray(), $debug);

        if (!$debug) {
            $this->insertId = $this->plugin()->getInsertId();
            if ($this->getAutoIncrementField()) {
                $autoIncrementField = $this->getAutoIncrementField();
                $this->$autoIncrementField = $this->insertId;
            }
            $this->select($this->mapPrimaryData($this->toArray()));
            $this->relateSave();
            $this->fire(self::EVENT_AFTER_CREATE);
        }

        return $result;
    }

    /**
     * 获取最后插入的记录ID
     * @return mixed
     */
    public function getInsertId()
    {
        return $this->insertId;
    }

    /**
     * 更新
     * @param array $data
     * @param bool $debug
     * @return bool
     * @throws Exception
     */
    public function update(array $data = array(), $debug = false)
    {
        if (empty($this->selected)) {
            ocService()->error->show('need_condition');
        }

        if (!$debug && $this->relations) {
            ocService()->transaction->begin();
        }

        $this->fire(self::EVENT_BEFORE_CREATE);

        if ($data){
            $oldData = array_intersect_key($this->toArray(), array_diff_key($data, $this->oldData));
            $this->oldData = array_merge($this->oldData, $oldData);
        }

        $data = array_merge($this->getChanged(), $data);
        call_user_func_array('ocDel', array(&$data, $this->getPrimaries()));
        $result = $this->plugin()->update($data, $debug);

        if (!$debug) {
            $this->relateSave();
            $this->fire(self::EVENT_AFTER_UPDATE);
        }

        return $result;
    }

    /**
     * 保存
     * @param array $data
     * @param bool $debug
     * @return bool
     * @throws Exception
     */
    public function save(array $data = array(), $debug = false)
    {
        if ($this->selected) {
            return $this->update($data, $debug);
        } else {
            return $this->create($data, $debug);
        }
    }

    /**
     * 删除
     * @param bool $debug
     * @return mixed
     */
    public function delete($debug = false)
    {
        if (empty($this->selected)) {
            ocService()->error->show('need_condition');
        }

        $this->fire(self::EVENT_BEFORE_DELETE);

        $result = $this->plugin()->delete();

        if (!$debug) {
            $this->fire(self::EVENT_AFTER_DELETE);
        }

        return $result;
    }

    /**
     * 赋值主键
     * @param $data
     * @return array
     */
    protected function mapPrimaryData($data)
    {
        $result = array();
        foreach ($this->plugin()->getPrimaries() as $field) {
            $result[$field] = array_key_exists($field, $data);
        }
        return $result;
    }

    /**
     * 获取主键条件
     * @param $condition
     * @return array
     */
    protected function getPrimaryCondition($condition)
    {
        $primaries = $this->plugin()->getPrimaries();
        if (empty($primaries)) {
            ocService()->error->show('no_primary');
        }

        if (ocEmpty($condition)) {
            ocService()->error->show('need_primary_value');
        }

        $values = array();
        if (is_string($condition) || is_numeric($condition)) {
            $values = explode(',', trim($condition));
        } elseif (is_array($condition)) {
            $values = $condition;
        } else {
            ocService()->error->show('fault_primary_value_format');
        }

        $where = array();
        if (count($primaries) == count($values)) {
            $where = $this->plugin()->filterData(array_combine($primaries, $values));
        } else {
            ocService()->error->show('fault_primary_num');
        }

        return $where;
    }

    /**
     * 关联查询
     * @param $alias
     * @return null|ObjectRecords
     */
    protected function relateFind($alias)
    {
        $config = $this->getRelateConfig($alias);
        $result = null;

        if ($config) {
            $where = array($config['foreignKey'] => $this->$config['primaryKey']);
            if (in_array($config['joinType'], array('hasOne','belongsTo'))) {
                $result = $config['class']::build()
                    ->where($where)
                    ->where($config['condition'])
                    ->findRow();
            } elseif ($config['joinType'] == 'hasMany') {
                $result = new ObjectRecords($config['class'], array($where, $config['condition']));
                $result->setLimit(0, 0, 1);
            }
        }

        return $result;
    }

    /**
     * 关联保存
     * @return bool
     * @throws Exception
     */
    protected function relateSave()
    {
        if (!$this->relations) {
            return true;
        }

        foreach ($this->relations as $key => $object) {
            $config = $this->getRelateConfig($key);
            if ($config && isset($this->$config['primaryKey'])) {
                $data = array();
                if ($config['joinType'] == 'hasOne' && is_object($object)) {
                    $data = array($object);
                } elseif ($config['joinType'] == 'hasMany') {
                    if (is_object($object)) {
                        $data = array($object);
                    } elseif (is_array($object)) {
                        $data = $object;
                    }
                }
                foreach ($data as &$entity) {
                    if ($entity->hasChanged() && is_object($entity) && $entity instanceof DatabaseEntity) {
                        $entity->$config['foreignKey'] = $this->$config['primaryKey'];
                        if ($config['condition']) {
                            foreach ($config['condition'] as $field => $value) {
                                $entity->$field = $value;
                            }
                        }
                        $entity->save();
                    }
                }
            }
        }

        return true;
    }

    /**
     * 获取无法访问的属性
     * @param string $key
     * @return mixed
     */
    public function &__get($key)
    {
        $relations = $this->plugin()->getConfig('RELATIONS');

        if (isset($relations[$key])) {
            if (!isset($this->relations[$key])) {
                $this->relations[$key] = $this->relateFind($key);
            }
            return $this->relations[$key];
        }

        return parent::__get($key);
    }

    /**
     * 设置无法访问的属性
     * @param $name
     * @param $value
     * @return bool|void
     * @throws Exception
     */
    public function __set($name, $value)
    {
        $relations = $this->plugin()->getConfig('RELATIONS');

        if (isset($relations[$name])) {
            $this->relations[$name] = $value;
        } else {
            $oldValue = null;
            if ($this->selected) {
                if (!array_key_exists($name, $this->oldData)){
                    $oldValue = $this->$name;
                }
            }
            parent::__set($name, $value);
            if ($this->selected && isset($this->$name)) {
                $this->changes[] = $name;
                $this->oldData[$name] = $oldValue;
            }
        }
    }
}