<?php
/*************************************************************************************************
 * -----------------------------------------------------------------------------------------------
 * Ocara开源框架   AJAX请求处理类Ajax
 * Copyright (c) http://www.ocara.cn All rights reserved.
 * -----------------------------------------------------------------------------------------------
 * @author Lin YiHu <linyhtianwa@163.com>
 ************************************************************************************************/
namespace Ocara\Core;

use Ocara\Core\Base;
use Ocara\Exceptions\Exception;

defined('OC_PATH') or exit('Forbidden!');

class Ajax extends Base
{

    /**
     * Ajax成功
     * @param $data
     * @param $message
     * @throws Exception
     */
    public function ajaxSuccess($data, $message)
    {
        $this->render('success', $message, $data);
    }

    /**
     * AJAX错误
     * @param $message
     * @param null $data
     * @throws Exception
     */
    public function ajaxError($message, $data = null)
    {
        $this->render('error', $message, $data);
    }

    /**
     * 获取XML结果
     * @param $result
     * @return mixed
     */
    private function getXmlResult($result)
    {
        $xmlObj = new Xml();
        $xmlObj->setData('array', array('root', $result));
        $xml = $xmlObj->getContent();

        return $xml;
    }

    /**
     * 渲染结果
     * @param $status
     * @param array $message
     * @param string $body
     * @throws Exception
     */
	public function render($status, $message = array(), $body = OC_EMPTY)
	{
	    $services = ocService();
        if (!is_array($message)) {
			$message = $services->lang->get($message);
		}

		$result['status'] 	= $status;
		$result['code']    	= $message['code'];
		$result['message'] 	= $message['message'];
		$result['body']    	= $body;

		if ($callback = ocConfig(array('SOURCE', 'ajax', 'return_result'), null)) {
			$result = call_user_func_array($callback, array($result));
		}

		$response = $services->response;
		$statusCode = $response->getOption('statusCode');

		if (!$statusCode && !ocConfig(array('API', 'is_send_error_code'), 0)) {
			$response->setStatusCode(Response::STATUS_OK);
			$result['statusCode'] = $response->getOption('statusCode');
		}

        $content = null;
		$contentType = $response->getOption('contentType');

		switch ($contentType)
		{
			case 'json':
				$content = json_encode($result);
				break;
			case 'xml':
				$content = $this->getXmlResult($result);
				break;
		}

        $response->setBody($content);
	}
}