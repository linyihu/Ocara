<?php
/*
 * 缓存配置
 */

//默认Memcache
$CONF['CACHE']['default'] = array(
	'open' 		=> 0, //是否启用
	'type' 		=> 'memcache',
	'servers' 	=> array(
		array('127.0.0.1', 11211)
	),
);

//Redis
$CONF['CACHE']['redis'] = array(
	'open' 		=> 0, //是否启用
	'type' 		=> 'redis',
	'host'		=> '127.0.0.1',
	'password'	=> ''
);