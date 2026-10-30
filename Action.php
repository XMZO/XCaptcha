<?php

/**
 * 极验验证插件执行
 */
class XCaptcha_Action extends Typecho_Widget implements Widget_Interface_Do
{
    public function execute()
    {
    }

    public function action()
    {
        $this->on($this->request->is('do=ajaxResponseCaptchaData'))->ajaxResponseCaptchaData();
    }

    public function ajaxResponseCaptchaData()
    {
        // if (!$this->request->isAjax()) {
        //     $this->response->redirect('/');
        // }
        $request = Typecho_Request::getInstance();
        $type = $request->get('type', 'altcha'); // 默认为 'altcha'
        // 找回密码页单独配置的验证码用另一套密钥
        $profile = (string) $request->get('profile', '');
        // 根据不同的参数调用不同的函数
        switch ($type) {
            case 'geetest':
                Typecho_Plugin::factory('XCaptcha')->responseGeetest($profile);
                break;
            case 'altcha':
                Typecho_Plugin::factory('XCaptcha')->responseAltcha();
                break;
            default:
                echo json_encode(['error' => 'Invalid captcha type']);
                break;
        }

    }
}