<?php
namespace Ocara\Controllers;

use Ocara\Core\ControllerBase;
use Ocara\Interfaces\Controller as ControllerInterface;

class Task extends ControllerBase implements ControllerInterface
{
    /**
     * 获取控制器类型
     */
    public static function controllerType()
    {
        return self::$controllerType ? ucfirst(self::$controllerType): static::CONTROLLER_TYPE_TASK;
    }

    /**
     * 执行动作
     * @param string $actionMethod
     */
    public function doAction($actionMethod)
    {
        if ($this->isActionClass()) {
            $this->doClassAction();
        } else {
            $this->$actionMethod();
        }
    }

    /**
     * 执行Action类
     */
    public function doClassAction()
    {
        if (method_exists($this, '__action')) {
            $this->__action();
        }
    }
}