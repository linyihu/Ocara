<?php
/**
 * Smarty模板调用插件类
 * @Copyright (c) http://www.ocara.cn and http://www.ocaraframework.com All rights reserved.
 * @author Lin YiHu <linyhtianwa@163.com>
 */

namespace Ocara\Service;

use Ocara\Core\ServiceBase;
use Ocara\Exceptions\Exception;
use Ocara\Service\Interfaces\Template as TemplateInterface;

class Smarty extends ServiceBase implements TemplateInterface
{
    /**
     * 析构函数
     * Smarty constructor.
     * @param $templateDir
     * @param int $perm
     * @throws Exception
     */
    public function __construct($templateDir, $perm = null)
    {
        if (!class_exists('\Smarty')) {
            ocService()->error->show('no_the_special_class', array('smarty'));
        }

        $plugin = $this->setPlugin(new \Smarty());
        $compileDir = ocPath('runtime', 'smarty/cmp/');
        $cacheDir = ocPath('runtime', 'smarty/cache/');

        $perm = $perm ?: 0755;
        ocCheckPath($templateDir, $perm, true);
        ocCheckPath($compileDir, $perm, true);
        ocCheckPath($cacheDir, $perm, true);

        $plugin->setTemplateDir($templateDir);
        $plugin->setCompileDir($compileDir);
        $plugin->setCacheDir($cacheDir);

        if (ocConfig(array('SMARTY', 'use_cache'), false)) {
            $plugin->cache_lifetime = 60;
            $plugin->caching = true;
        } else {
            $plugin->caching = false;
        }

        $plugin->left_delimiter = ocConfig(array('SMARTY', 'left_sign'));
        $plugin->right_delimiter = ocConfig(array('SMARTY', 'right_sign'));
    }

    /**
     * 设置变量
     * @param string $name
     * @param mixed $value
     * @throws Exception
     */
    public function set($name, $value)
    {
        $this->plugin()->assign($name, $value);
    }

    /**
     * 获取变量
     * @param string $name
     * @return mixed
     * @throws Exception
     */
    public function get($name = null)
    {
        return $this->plugin()->getTemplateVars($name);
    }

    /**
     * 注册对象
     * @param array $params
     * @throws Exception
     */
    public function registerObject($params)
    {
        $plugin = $this->plugin();
        call_user_func_array(array(&$plugin, 'registerObject'), $params);
    }

    /**
     * 注册插件
     * @param string $params
     * @throws Exception
     */
    public function registerPlugin($params)
    {
        $plugin = $this->plugin();
        call_user_func_array(array(&$plugin, 'registerPlugin'), $params);
    }

    /**
     * 显示文件
     * @param string $file
     * @throws Exception
     */
    public function display($file)
    {
        $this->plugin()->display($file . '.tpl');
    }
}
