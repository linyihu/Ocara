<?php
/*
 * 数据库操作配置
 */
$CONF['DATABASE_FILTER_SQL_KEYWORDS'] = 1; //是否过滤指定的SQL关键字
$CONF['DATABASE_LIMIT_CONNECT_TIMES'] = 3; //连接数据库失败尝试次数

/*
 * 数据库连接配置
 * 如果是大型分布式数据库，请配置回调函数动态生成配置
 * 配置路径：develop.php中的$CONF'CALLBACK']['database']的config配置项
 */
$CONF['DATABASE']['default'] = array(
	'type' 		=> 'mysql', //数据库类型
	'host' 		=> '127.0.0.1', //数据库服务器主机和端口，默认端口可以省略
	'default' 	=> '', //默认数据库名称
	'username' 	=> '', //数据库用户名
	'password' 	=> '', //数据库用户密码
	'charset' 	=> '', //数据库编码，如果是utf8不要写成utf-8
	'prefix' 	=> '', //表名前缀
	'keywords'  => '', //SQL关键字
);
