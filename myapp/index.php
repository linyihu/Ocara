<?php
/*
* 框架所在目录,需配置正确
 */
define('OC_PATH', '../ocara');

/*
 * 运行应用
 */
require_once(OC_PATH . '/system/Ocara.php');
Ocara::create();