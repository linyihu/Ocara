<?php
/*************************************************************************************************
 * -----------------------------------------------------------------------------------------------
 * Ocara开源框架   Session文件方式处理类SessionFile
 * Copyright (c) http://www.ocara.cn All rights reserved.
 * -----------------------------------------------------------------------------------------------
 * @author Lin YiHu <linyhtianwa@163.com>
 ************************************************************************************************/
namespace Ocara\Sessions;

use Ocara\Ocara;
use Ocara\Base;
use Ocara\CacheFactory;
use Ocara\Exceptions\Exception;
use Ocara\ServiceProvider;

defined('OC_PATH') or exit('Forbidden!');

class SessionCache extends ServiceProvider
{
	
    private $_plugin;
    private $_prefix;

    /**
     * 注册服务
     * @throws Exception
     */
    public function register()
    {
        parent::register(); // TODO: Change the autogenerated stub

        $cacheName = ocConfig('SESSION.server', 'default');
        $this->_container->bindSingleton('_plugin', function () use ($cacheName){
            CacheFactory::create($cacheName);
        });
    }

    /**
     * 启动
     */
    public function boot()
    {
        $prefix = ocConfig('SESSION.location', 'session') . '_';
        $this->_prefix = $prefix . '_';
    }
    
    /**
     * session打开
     */
    public function open()
    {
		if (is_object($this->_plugin)) {
			return true;
		}
        return false;
    }

    /**
     * session关闭
     */
    public function close()
    {
        return true;
    }

    /**
     * 读取session信息
     * @param string $id
     * @return bool
     */
    public function read($id)
    {
    	$this->_plugin->get($this->_prefix . $id);
    	return false;
    }

    /**
     * 保存session
     * @param string $id
     * @param string $data
     * @return bool
     */
    public function write($id, $data)
    {
        try {
            $this->_plugin->set($this->_prefix . $id, $data);
        } catch(Exception $exception) {
            Ocara::services()->error->show($exception->getMessage());
        }

        return true;
    }

    /**
     * 销毁session
     * @param string $id
     * @return bool
     */
    public function destroy($id)
    {
        $this->_plugin->delete($this->_prefix . $id);
        return true;
    }

    /**
     * Session垃圾回收
     * @param integer $maxlifetime
     * @return bool
     */
    public function gc($maxlifetime)
    {
        return true;
    }
}