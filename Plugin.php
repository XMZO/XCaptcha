<?php

/**
 * 设置评论验证码
 *
 * @package XCaptcha
 * @author CairBin
 * @version 1.3.1
 * @link https://cairbin.top
 * contributor: yumusb(https://github.com/yumusb)
 */

include "lib/class.geetestlib.php";
include_once "includes/XCaptcha_Config.php";
include_once "includes/XCaptcha_Validator.php";
include_once "includes/XCaptcha_Render.php";

if (!defined("__TYPECHO_ROOT_DIR__")) {
    exit();
}

class XCaptcha_Plugin implements Typecho_Plugin_Interface
{
    /**
     * Activate the plugin
     */
    public static function activate()
    {
        // comments hook
        Typecho_Plugin::factory("Widget_Feedback")->comment = [
            __CLASS__,
            "filter",
        ];
        Typecho_Plugin::factory("Widget_Feedback")->trackback = [
            __CLASS__,
            "filter",
        ];
        Typecho_Plugin::factory("Widget_XmlRpc")->pingback = [
            __CLASS__,
            "filter",
        ];

        // Login page hook
        Typecho_Plugin::factory("admin/footer.php")->end = [
            __CLASS__,
            "renderLoginCaptcha",
        ];
        // 登录/注册：在 Typecho 核对密码、写入新用户之前校验
        Typecho_Plugin::factory("index.php")->begin = [
            __CLASS__,
            "verifyAuthCaptcha",
        ];
        Typecho_Plugin::factory("XCaptcha")->responseGeetest = [
            __CLASS__,
            "responseGeetest",
        ];
        Typecho_Plugin::factory("XCaptcha")->responseAltcha = [
            __CLASS__,
            "responseAltcha",
        ];

        Helper::addAction("xcaptcha", "XCaptcha_Action");
        
        return _t('插件已经激活');
    }

    /**
     * Deactivate the plugin
     */
    public static function deactivate()
    {
        Helper::removeAction("xcaptcha");
    }
    /**
     * Config panel
     */
    public static function config(Typecho_Widget_Helper_Form $form)
    {
        XCaptcha_Config::config($form);
    }

    /**
     * personal config
     */
    public static function personalConfig(Typecho_Widget_Helper_Form $form){}

    /**
     * 本次请求的登录/注册验证码已经由 verifyAuthCaptcha 校验通过
     */
    private static $authCaptchaPassed = false;

    /**
     * 按当前配置的验证工具校验提交上来的验证码
     */
    private static function verifySubmission(XCaptcha_Config $config)
    {
        switch ($config->getCaptchaChoosen()) {
            case "geetest":
                return (bool) XCaptcha_Validator::verifyGeetest($config);
            case "altcha":
                return XCaptcha_Validator::verifyAltchaSolution($config);
            default:
                return (bool) XCaptcha_Validator::verifyOtherCaptcha($config);
        }
    }

    /**
     * 登录/注册验证码，挂在 index.php:begin，早于 Typecho 核对密码和写入新用户。
     * 失败时不会走到密码校验，也就不会因提示不同而暴露密码是否正确。
     */
    public static function verifyAuthCaptcha()
    {
        $request = Typecho_Widget::widget("Widget_Options")->request;
        if (!$request->isPost()) {
            return;
        }
        $pathInfo = rtrim((string) $request->getPathInfo(), "/");
        if ($pathInfo !== "/action/login" && $pathInfo !== "/action/register") {
            return;
        }

        $config = new XCaptcha_Config();
        if (!$config->isCaptchaEnabledOnPage("login")) {
            return;
        }
        if (!$config->checkKeys()) {
            Typecho_Widget::widget("Widget_Notice")->set(
                _t("XCaptcha: No keys."),
                "error"
            );
            return;
        }

        if (!self::verifySubmission($config)) {
            Typecho_Widget::widget("Widget_Notice")->set(
                _t("Captcha verification failed."),
                "error"
            );
            Typecho_Widget::widget("Widget_Options")->response->goBack();
        }
        self::$authCaptchaPassed = true;
    }

    /**
     * 旧版挂在 Widget_User::loginSucceed（密码核对之后）。重新启用插件后不再挂这个钩子；
     * 没重新启用的站点仍会调到这里，保持旧行为兜底，新钩子已校验过则跳过。
     */
    public static function verifyLoginCaptcha()
    {
        if (self::$authCaptchaPassed) {
            return;
        }
        $config = new XCaptcha_Config();
        if (!$config->isCaptchaEnabledOnPage("login") || !$config->checkKeys()) {
            return;
        }
        if (!self::verifySubmission($config)) {
            Typecho_Widget::widget("Widget_Notice")->set(
                _t("Captcha verification failed."),
                "error"
            );
            Typecho_Widget::widget("Widget_User")->logout();
            Typecho_Widget::widget("Widget_Options")->response->goBack();
        }
    }

    /**
     * 过滤评论
     * @param {*} $comment
     * @return {*}
     */
    public static function filter($comment)
    {
        $config = new XCaptcha_Config();
        // 没启动评论区验证码则直接通过
        if (!$config->isCaptchaEnabledOnPage("comments")) {
            return $comment;
        }
        // 未填写密钥则通过
        if (!$config->checkKeys()) {
            Typecho_Widget::widget("Widget_Notice")->set(
                _t("XCaptcha: No keys."),
                "error"
            );
            return $comment;
        }
        // 是否开启登录用户不校验 且 用户处于登录状态 且 为administrator，都符合则不校验
        $user = Typecho_Widget::widget("Widget_User");
        if (
            $config->isAuthorUncheck() &&
            $user->hasLogin() &&
            $user->pass("administrator", true)
        ) {
            return $comment;
        }

        if (!self::verifySubmission($config)) {
            $messages = [
                "geetest" => "Geetest:Invalid verification code.",
                "altcha" => "Altcha:Invalid verification code.",
            ];
            throw new Typecho_Widget_Exception(
                _t($messages[$config->getCaptchaChoosen()] ?? "Invalid verification code.")
            );
        }
        return $comment;
    }

    /**
     * 评论区渲染验证码
     */
    public static function showCaptcha()
    {
        $config = new XCaptcha_Config();
        XCaptcha_Render::renderCommentPage($config);
    }

    public static function renderLoginCaptcha()
    {
        $config = new XCaptcha_Config();
        XCaptcha_Render::renderLoginPage($config);
    }

    /**
     * 前端登录/注册表单渲染验证码（用于主题中的登录表单）
     */
    public static function showLoginCaptcha()
    {
        $config = new XCaptcha_Config();
        XCaptcha_Render::renderFrontendLoginForm($config);
    }

    /**
     * 给Action使用
     */
    public static function responseGeetest()
    {
        $config = new XCaptcha_Config();
        @session_start();
        $geetestSdk = new GeetestLib(
            $config->getCaptchaId(),
            $config->getSecretKey()
        );

        $widgetRequest = Typecho_Widget::widget("Widget_Options")->request;
        $agent = $widgetRequest->getAgent();

        $data = [
            "user_id" => rand(1000, 9999),
            "client_type" => XCaptcha_Utils::isMobile($agent) ? "h5" : "web",
            "ip_address" => $widgetRequest->getIp(),
        ];

        $_SESSION["gt_server_ok"] = $geetestSdk->pre_process($data, 1);
        $_SESSION["gt_user_id"] = $data["user_id"];

        echo $geetestSdk->get_response_str();
    }

    /**
     * 处理验证码响应的钩子函数
     */
    public static function responseAltcha()
    {
        require_once __DIR__. "/lib/class.altcha.php";

        // 初始化配置
        $hmacKey = bin2hex(random_bytes(32)); // 生成一个随机的 HMAC 密钥
        $algorithm = \AltchaOrg\Altcha\Altcha::DEFAULT_ALGORITHM; // 使用默认算法 SHA-256
        $maxNumber = 40000; // 最大数字范围
        $saltLength = 12; // Salt 长度
        $expires = time() + XCaptcha_Validator::ALTCHA_TTL;

        // 创建挑战选项；expires 写进 salt，组件到期会自己变成「已过期」
        $options = [
            "algorithm" => $algorithm,
            "maxNumber" => $maxNumber,
            "saltLength" => $saltLength,
            "hmacKey" => $hmacKey,
            "expires" => (new DateTime())->setTimestamp($expires),
        ];

        // 生成挑战
        $challenge = \AltchaOrg\Altcha\Altcha::createChallenge($options);

        // 一个页面上可能同时有评论、登录、注册几个组件，按题目分别存，互不覆盖
        @session_start();
        XCaptcha_Validator::storeAltchaChallenge($challenge->challenge, $hmacKey, $expires);

        // 返回挑战信息给客户端
        $response = [
            "algorithm" => $challenge->algorithm,
            "challenge" => $challenge->challenge,
            "maxNumber" => $challenge->maxnumber,
            "salt" => $challenge->salt,
            "signature" => $challenge->signature,
        ];
    
        // 输出 JSON 响应
        header("Content-Type: application/json");
        echo json_encode($response);
    }
}
