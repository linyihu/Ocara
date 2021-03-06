<?php
/**
 * SQL语句生成类
 * @Copyright (c) http://www.ocara.cn and http://www.ocaraframework.com All rights reserved.
 * @author Lin YiHu <linyhtianwa@163.com>
 */

namespace Ocara\Core;

use Ocara\Exceptions\Exception;

class Sql extends Base
{
    protected $database;
    protected $params = array();
    protected $config = array();

    /**
     * Sql constructor.
     * @param string $database
     */
    public function __construct($database)
    {
        $this->database = $database;
    }

    /**
     * 设置数据库配置
     * @param array $config
     */
    public function setConfig($config)
    {
        $this->config = $config;
    }

    /**
     * 获取设置编码SQL
     * @param string $charset
     * @return string
     */
    public function getSetCharsetSql($charset)
    {
        return 'SET NAMES ' . $charset;
    }

    /**
     * 切换数据库的SQL
     * @param string $name
     * @return string
     */
    public function getSelectDbSql($name)
    {
        return "USE " . $name;
    }

    /**
     * 给逗号分隔的列表加引号
     * @param array|string $list
     * @param string $quote
     * @return array|bool|mixed|string
     * @throws Exception
     */
    public function quoteList($list, $quote = OC_QUOTE)
    {
        $sql = $list;

        if (is_array($list)) {
            if ($list) {
                if (is_numeric(reset($list))) {
                    $sql = implode(',', $list);
                    $sql = $this->parseValue($sql, 'where', false, false);
                } else {
                    $sql = implode(OC_QUOTE . ',' . OC_QUOTE, $list);
                    $sql = $this->parseValue($sql, 'where', true, false);
                }
            } else {
                $sql = OC_EMPTY;
            }
        }

        return $sql;
    }

    /**
     * 字段名或表名等过滤
     * @param string $name
     * @return array|string
     * @throws Exception
     */
    public function filterName($name)
    {
        return ocService()->filter->addSlashes($name);
    }

    /**
     * 获取表全名
     * @param string $table
     * @param string $database
     * @return array|mixed|string
     * @throws Exception
     */
    public function getTableFullname($table, $database = null)
    {
        if (preg_match('/^' . OC_SQL_TAG . '(.*)$/i', $table, $mt)) {
            return $mt[1];
        }

        if (preg_match('/(\w+)\.(\w+)/i', $table, $mt)) {
            $databaseName = $mt[1];
            $table = $mt[2];
        } else {
            $databaseName = $database ?: $this->config['name'];
            if ($this->config['prefix']) {
                $table = $this->config['prefix'] . $table;
            }
        }

        $tableFullName = $this->filterName($this->getTableNameSql($databaseName, $table));
        return $tableFullName;
    }

    /***
     * SQL安全过滤
     * @param string $content
     * @param bool $addSlashes
     * @return array|bool|mixed|string
     * @throws Exception
     */
    public function filterValue($content, $addSlashes = true)
    {
        if ($mt = self::checkSqlTag($content)) {
            return $mt[1];
        } else {
            if ($addSlashes) {
                $str = $this->database->escapeString($content);
                if ($str !== false) {
                    return $this->filterSql($str, false);
                }
            }
            return $this->filterSql($content, $addSlashes);
        }
    }

    /**
     * 值格式解析
     * @param mixed $value
     * @param string $paramType
     * @param bool $ifQuote
     * @param bool $prepare
     * @return array|bool|mixed|string
     * @throws Exception
     */
    public function parseValue($value, $paramType = 'where', $ifQuote = true, $prepare = true)
    {
        if (ocScalar($value)) {
            if ($mt = self::checkSqlTag($value)) {
                return $mt[1];
            } else {
                if ($this->database->isPrepare() && $prepare) {
                    $value = $this->filterSql($value, false);
                    $this->params[$paramType][] = $value;
                    return '?';
                }
                return $this->getValueSql($value, $ifQuote);
            }
        }

        ocService()->error->show('need_string_field');
    }

    /**
     * [别名.]字段解析
     * @param string $field
     * @param string $alias
     * @return bool|string
     * @throws Exception
     */
    public function parseField($field, $alias = null)
    {
        if (!is_string($field)) {
            ocService()->error->show('invalid_field_name');
        }

        if ($mt = self::checkSqlTag($field)) {
            return $mt[1];
        }

        $field = $this->filterName($field);

        if (empty($field)) return false;

        return $this->getFieldNameSql($field, $alias);
    }

    /**
     * SQL过滤
     * @param string|array $content
     * @param bool $addSlashes
     * @param bool $equal
     * @return array|bool|mixed|string
     * @throws Exception
     */
    public function filterSql($content, $addSlashes = true, $equal = false)
    {
        return ocService()->filter->sql($content, $addSlashes, $this->config['keywords'], $equal);
    }

    /**
     * 字段转换
     * @param string $sql
     * @param array $mapData
     * @param string $currentAlias
     * @param bool $field2Alias
     * @return string
     */
    public function transformFields($sql, $mapData, $currentAlias, $field2Alias = false)
    {
        $exp = '/([^\w\.]+)+((\w+)\.)?(%s)([^\w\.]+)+/i';
        $newSql = chr(32) . $sql . chr(32);

        foreach ($mapData as $alias => $row) {
            if ($field2Alias) {
                $row = array_flip($row);
            }
            foreach ($row as $search => $replace) {
                if (preg_match(sprintf($exp, $search), $newSql, $mt)) {
                    if (!$mt[3]) {
                        $mt[3] = $currentAlias;
                    }
                    if ($alias == $mt[3]) {
                        $newSql = $this->getFieldAliasSql($sql, $replace);
                    }
                    return trim($newSql);
                }
            }
        }

        return $sql;
    }

    /**
     * 获取字段别名SQL
     * @param string $field
     * @param string $alias
     * @return string
     */
    public function getFieldAliasSql($field, $alias)
    {
        return $field ? $field . ' AS ' . $alias : OC_EMPTY;
    }

    /**
     * 检查SQL代码标记
     * @param string $value
     * @return array
     */
    public static function checkSqlTag($value)
    {
        if (preg_match('/^' . OC_SQL_TAG . '(.*)$/i', $value, $mt)) {
            return $mt;
        }
        return array();
    }

    /**
     * 检查是否是字符串或数字条件
     * @param mixed $condition
     * @return bool
     * @throws Exception
     */
    public function checkStringCondition($condition)
    {
        if (ocScalar($condition)) {
            return true;
        }
        ocService()->error->show('need_scalar_condition');
    }

    /**
     * SELECT语句
     * @param string $fields
     * @param string $tables
     * @param array $options
     * @return array
     * @throws Exception
     */
    public function getSelectSql($fields, $tables, $options)
    {
        $sql = "SELECT {$fields} FROM {$tables} "
            . $this->getOptionSql('WHERE', $options)
            . $this->getOptionSql('GROUP', $options)
            . $this->getOptionSql('HAVING', $options)
            . $this->getOptionSql('ORDER', $options)
            . $this->getOptionSql('LIMIT', $options)
            . $this->getOptionSql('MORE', $options);

        return $this->getSqlData($sql);
    }

    /**
     * INSERT语句
     * @param string $table
     * @param array $data
     * @return array
     * @throws Exception
     */
    public function getInsertSql($table, $data)
    {
        $sql = $this->getInsertSqlBase('INSERT', $table, $data);
        return $this->getSqlData($sql);
    }

    /**
     * INSERT基本语句
     * @param string $type
     * @param string $table
     * @param string|array $data
     * @return string
     * @throws Exception
     */
    public function getInsertSqlBase($type, $table, $data)
    {
        $fields = $values = array();

        foreach ($data as $key => $value) {
            $fields[] = $this->parseField($key);
            $values[] = $this->parseValue($value, 'set');
        }

        $fields = implode(',', $fields);
        $values = implode(',', $values);

        return $type . " INTO {$table}({$fields}) VALUES({$values})";
    }

    /**
     * 获取更新语句SQL
     * @param string $table
     * @param string|array $data
     * @param string|array $where
     * @return array
     * @throws Exception
     */
    public function getUpdateSql($table, $data, $where)
    {
        $set = OC_EMPTY;
        $where = $this->parseCondition($where);
        $this->checkStringCondition($where);

        if (is_array($data)) {
            $array = array();
            foreach ($data as $key => $value) {
                $key = $this->parseField($key);
                $value = $this->parseValue($value, 'set');
                $array[] = "{$key} = {$value}";
            }
            $set = implode(',', $array);
        }

        $sql = "UPDATE {$table} SET {$set} " . ($where ? " WHERE {$where} " : OC_EMPTY);
        return $this->getSqlData($sql);
    }

    /**
     * REPLACE语句
     * @param string $table
     * @param string|array $data
     * @return array
     * @throws Exception
     */
    public function getReplaceSql($table, $data)
    {
        $sql = $this->getInsertSqlBase('REPLACE', $table, $data);
        return $this->getSqlData($sql);
    }

    /**
     * DELETE语句
     * @param string $table
     * @param string|array $where
     * @param string $option
     * @return array
     * @throws Exception
     */
    public function getDeleteSql($table, $where, $option = null)
    {
        $where = $this->parseCondition($where);
        $this->checkStringCondition($where);
        $option = $this->filterName($option);

        $sql = "DELETE {$option} FROM {$table}" . ($where ? " WHERE {$where} " : OC_EMPTY);
        return $this->getSqlData($sql);
    }

    /**
     * 获取表的字段信息
     * @param string $table
     * @param string $database
     * @return string
     * @throws Exception
     */
    public function getShowFieldsSql($table, $database = null)
    {
        $tableName = $this->getTableFullname($table, $database);
        return "SHOW FULL FIELDS FROM {$tableName}";
    }

    /**
     * 获取联接语句
     * @param string $type
     * @param string $table
     * @param string $alias
     * @param string $on
     * @return string
     * @throws Exception
     */
    public function getJoinSql($type, $table, $alias, $on)
    {
        $type = strtoupper($type ? $type . ' JOIN ' : false);
        $alias = $this->filterName($alias);
        $on = $this->parseCondition($on);
        $data = $this->wrapSign(array($type, $alias, $on));
        $sql = $data[0] . $table;

        if ($data[1]) {
            $sql = $sql . ' AS ' . $data[1];
        }

        if ($data[2]) {
            $sql = $sql . ' ON ' . $data[2];
        }

        return $sql;
    }

    /**
     * 获取最后一次插入的ID
     * @return string
     */
    public function getLastIdSql()
    {
        return "SELECT last_insert_id() AS id";
    }

    /**
     * 获取limit字符串
     * @param int|array $limit
     * @return array|bool|mixed|string
     * @throws Exception
     */
    public function getLimitSql($limit)
    {
        if (is_array($limit) && count($limit) >= 2) {
            $limit = "{$limit[0]}, {$limit[1]}";
        }

        $str = $this->database->escapeString($limit);
        if ($str !== false) {
            return $this->filterSql($str, false, true);
        }

        return $this->filterSql($limit, true, true);
    }

    /**
     * 获取In语句
     * @param string $field
     * @param string $list
     * @param string $alias
     * @param string $sign
     * @return string
     * @throws Exception
     */
    public function getInSql($field, $list, $alias = null, $sign = 'IN')
    {
        $sign = $sign ?: 'IN';

        if (is_array($list)) {
            $list = $this->quoteList($list);
        }

        $field = $this->parseField($field, $alias);

        if (ocScalar($list) && $field) {
            return " {$field} {$sign} ($list)";
        }

        return OC_EMPTY;
    }

    /**
     * 获取Between语句
     * @param string $field
     * @param string $value1
     * @param mixed $value2
     * @param string $alias
     * @return string
     * @throws Exception
     */
    public function getBetweenSql($field, $value1, $value2, $alias = null)
    {
        $value1 = $this->parseValue($value1, 'where');
        $value2 = $this->parseValue($value2, 'where');

        return $this->parseField($field, $alias) . " BETWEEN {$value1} AND {$value2}";
    }

    /**
     * 获取统计字段SQL
     * @param string $countFiled
     * @param string $fieldName
     * @param bool $isGroup
     * @return array|string
     * @throws Exception
     */
    public function getCountSql($countFiled = null, $fieldName = 'total', $isGroup = false)
    {
        $fieldName = $this->filterName($fieldName);

        if ($countFiled) {
            $sql = ocService()->filter->addSlashes("COUNT({$countFiled}) AS {$fieldName}");
        } else {
            $countFiled = $isGroup ? 1 : 'COUNT(1)';
            $sql = ocService()->filter->addSlashes("{$countFiled} AS {$fieldName}");
        }

        return $sql;
    }

    /**
     * 获取选项语句
     * @param string $type
     * @param string|array $options
     * @return array|bool|mixed|string
     * @throws Exception
     */
    public function getOptionSql($type, $options)
    {
        if (empty($options)) return false;

        $lowerType = strtolower($type);

        if (is_string($options) && $type == 'WHERE') {
            return $options ? " WHERE {$options} " : OC_EMPTY;
        }

        if (is_array($options) && array_key_exists($lowerType, $options)) {
            $content = $options[$lowerType];
            switch ($type) {
                case 'WHERE':
                case 'HAVING':
                    $this->checkStringCondition($content);
                    break;
                case 'MORE':
                    if ($content && is_array($content)) {
                        $content = array_map(array($this, 'filterValue'), $content);
                        $content = OC_SPACE . implode(OC_SPACE, $content);
                    }
                    return $content;
            }
            if (in_array($type, array('GROUP', 'ORDER'))) {
                $type = $type . ' BY';
            }
            return $content ? " {$type} {$content} " : OC_EMPTY;
        }

        return false;
    }

    /**
     * 解析查询条件
     * @param string $condition
     * @param string $link
     * @param string $sign
     * @param string $alias
     * @return bool|string
     * @throws Exception
     */
    public function parseCondition($condition, $link = 'AND', $sign = '=', $alias = null)
    {
        if (ocEmpty($condition)) return false;

        if (is_array($condition) && $condition) {
            $condition = trim($this->getFieldCondition($condition, $link, $sign, $alias));
            return $condition;
        }

        $condition = $this->filterValue($condition);
        $alias = $this->getAliasSql($alias);

        return $this->wrapWhere($alias . (string)$condition);
    }

    /**
     * 获取别名SQL
     * @param string $alias
     * @return bool|string
     * @throws Exception
     */
    public function getAliasSql($alias)
    {
        if ($alias) {
            $alias = $this->filterName($alias);
            return "`{$alias}`";
        }

        return false;
    }

    /**
     * 组装条件（加上括号）
     * @param $str
     * @return string
     */
    public static function wrapWhere($str)
    {
        return '(' . $str . ')';
    }

    /**
     * 获取字段列表SQL
     * @param array $fields
     * @param array $aliasFields
     * @param string $currentAlias
     * @param string $alias
     * @return string
     */
    public function getFieldsSql(array $fields, array $aliasFields, $currentAlias, $alias = null)
    {
        foreach ($fields as $key => $value) {
            if (!self::isOptionFieldSql($value)) {
                $value = $this->getFieldNameSql($value, $alias);
                if (!preg_match('/\sas\s/i', $value, $mt)) {
                    $value = $this->transformFields($value, $aliasFields, $currentAlias, true);
                }
                $fields[$key] = $value;
            }
        }

        return implode(',', $fields);
    }

    /**
     * 字段组合
     * @param array $fields
     * @param array $aliasFields
     * @param bool $unJoined
     * @param string $currentAlias
     * @param array $primaries
     * @return mixed
     */
    public function combineFieldsSql(array $fields, array $aliasFields, $unJoined, $currentAlias, array $primaries = array())
    {
        foreach ($fields as $key => $value) {
            if ($option = self::isOptionFieldSql($value)) {
                $fields[$key] = $option;
            } else {
                $fields[$key] = $value . ',';
            }
        }

        $primaryFields = array();
        foreach ($primaries as $primaryField) {
            if (!in_array($primaryField, $fields)) {
                $primaryFields[] = $primaryField;
            }
        }

        $alias = $unJoined ? false : $currentAlias;
        $primaryFields = $this->getFieldsSql($primaryFields, $aliasFields, $currentAlias, $alias);
        if ($primaryFields) {
            $fields[] = $primaryFields;
        }

        return trim(implode(OC_SPACE, $fields), ',');
    }

    /**
     * 是否字段选项
     * @param string $value
     * @return string|null
     */
    public static function isOptionFieldSql($value)
    {
        if (preg_match('/^\{(.*)\}$/', $value, $mt)) {
            return $mt[1];
        }

        return null;
    }

    /**
     * 获取字段名称SQL
     * @param string $field
     * @param string $alias
     * @return string
     */
    public function getFieldNameSql($field, $alias = null)
    {
        if ($this->hasAlias($field) || $field == '*') {
            return $field;
        }

        if ($alias) {
            return "{$alias}.{$field}";
        }

        $field = ocIsStandardName($field) ? "`{$field}`" : $field;
        return $field;
    }

    /**
     * 检测是否含有别名
     * @param string $field
     * @return bool
     */
    public function hasAlias($field)
    {
        if (preg_match('/^([`\w]*)\.(([`\s\w]+)|(\*))$/', $field, $mt)) {
            return $mt;
        }

        return false;
    }

    /**
     * 获取字段值SQL
     * @param string $val
     * @param bool $ifQuote
     * @return array|bool|mixed|string
     * @throws Exception
     */
    public function getValueSql($val, $ifQuote = true)
    {
        if (is_numeric($val)) return $val;

        if ($val === null) return 'NULL';

        $str = $this->database->escapeString($val);

        if ($str !== false) {
            $val = $this->filterSql($str, false);
        } else {
            $val = $this->filterSql($val);
        }

        $val = $ifQuote ? OC_QUOTE . $val . OC_QUOTE : $val;
        return $val;
    }

    /**
     * 给符号加空格
     * @param string $sign
     * @return array|string|null
     */
    public function wrapSign($sign)
    {
        if (is_array($sign)) {
            foreach ($sign as $key => $value) {
                if ($value) {
                    $sign[$key] = OC_SPACE . $value . OC_SPACE;
                } else {
                    $sign[$key] = null;
                }
            }
            return $sign;
        }

        return $sign ? OC_SPACE . $sign . OC_SPACE : null;
    }

    /**
     * 获取表名
     * @param string $databaseName
     * @param string $tableName
     * @return string
     */
    public function getTableNameSql($databaseName, $tableName)
    {
        return $databaseName ? "`{$databaseName}`.`$tableName`" : "`$tableName`";
    }

    /**
     * 格式化键值数组
     * @param string|array $data
     * @param string $link
     * @param string $sign
     * @param string $alias
     * @return string
     * @throws Exception
     */
    public function getFieldCondition($data, $link = ',', $sign = '=', $alias = null)
    {
        if (!is_array($data) || empty($data)) {
            return $data;
        }

        $link = $link ?: ',';
        $sign = $sign ? trim(ocService()->filter->replaceSpace($sign)) : '=';
        $result = array();

        if (in_array(strtoupper($sign), array('IN', 'NOT IN'))) {
            foreach ($data as $key => $value) {
                $result[] = $this->getInSql($key, $value, $alias, $sign);
            }
        } else {
            foreach ($data as $key => $value) {
                $newSign = $sign;
                $field = $this->parseField($key, $alias);
                if (is_array($value)) {
                    if ($value) {
                        if (count($value) == 1) {
                            $value = $value[0];
                        } else {
                            $newSign = $value[0];
                            $value = $value[1];
                            if (in_array(strtoupper($newSign), array('IN', 'NOT IN'))) {
                                $result[] = $this->getInSql($key, $value, $alias, $newSign);
                                continue;
                            }
                        }
                    } else { //空数组报错
                        ocService()->error->show('no_condition_array_value');
                    }
                }
                $value = $this->parseValue($value, 'where');
                $result[] = "({$field} {$newSign} {$value})";
            }
        }

        return implode($this->wrapSign($link), $result);
    }

    /**
     * 生成条件字符串
     * @param string|array $data
     * @return bool|string
     */
    public function getConditionSql($data)
    {
        if (empty($data)) return false;
        if (!is_array($data)) return $data;

        $where = array();
        $length = count($data);

        for ($i = 0; $i < $length; $i++) {
            $row = $data[$i];
            if (!is_array($row)) {
                $row = array('where' => $row, 'link' => 'AND');
            }
            if (is_array($row['where'])) {
                $row['where'] = implode($this->wrapSign('AND'), $row['where']);
            }
            if ($i == 0) {
                $where[] = $row['where'];
            } else {
                $where[] = $row['link'] . OC_SPACE . $row['where'];
            }
        }

        return implode(OC_SPACE, $where);
    }

    /**
     * 获取合并查询语句
     * @param string $sql
     * @param bool $unionAll
     * @return string
     */
    public function getUnionSql($sql, $unionAll = false)
    {
        return ($unionAll ? ' UNION ALL ' : ' UNION ') . '(' . $sql . ')';
    }

    /**
     * 获取子查询SQL
     * @param string $sql
     * @param array|string $options
     * @return string
     */
    public function getSubQuerySql($sql, $options = null)
    {
        $fields = '*';
        $orderBy = null;
        $limit = array();

        if (is_string($options)) {
            if ($options) {
                $fields = $options;
            }
        } else {
            if (!empty($options['fields'])) {
                $fields = $options['fields'];
            }
            if (!empty($options['orderBy'])) {
                $orderBy = $options['orderBy'];
            }
            if (!empty($options['limit'])) {
                $limit = $options['limit'];
            }
        }

        $querySql = "SELECT {$fields} FROM ({$sql}) AS a";

        if ($orderBy) {
            $querySql .= ' ORDER BY ' . $orderBy;
        }

        if ($limit) {
            $querySql .= ' LIMIT ' . implode(',', $limit);
        }

        return $querySql;
    }

    /**
     * 将SQL用括号括起来
     * @param string $sql
     * @return string
     */
    public function wrapSql($sql)
    {
        return '(' . $sql . ')';
    }

    /**
     * 将条件数组连接成条件字符串
     * @param array $data
     * @return string
     */
    public function linkWhere(array $data)
    {
        $sql = OC_EMPTY;

        foreach ($data as $row) {
            list($link, $condition) = $row;
            if (!$sql) {
                $sql = $condition;
            } else {
                $sql .= $this->wrapSign($link ? $link : 'AND') . OC_SPACE . $condition;
            }
        }

        return $sql;
    }

    /**
     * 绑定占位符参数
     * @param string $name
     * @param string|number $value
     */
    public function bind($name, $value)
    {
        if (preg_match('/^:\w+$/', $name)) {
            $this->params['bind'][$name] = $value;
        }
    }

    /**
     * 获取绑定参数
     * @return array
     */
    public function getBindParams()
    {
        return $this->params;
    }

    /**
     * 获取SQL语句数据
     * @param string $sql
     * @return array
     */
    public function getSqlData($sql)
    {
        $params = $this->params ? array($this->params) : array();
        $this->params = array();
        $sql = trim($sql);
        $data = array($sql, $params);

        return $data;
    }
}