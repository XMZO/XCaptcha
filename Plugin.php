<?php

/**
 * 设置评论验证码
 *
 * @package XCaptcha
 * @author CairBin
 * @version 1.5.1
 * @link https://cairbin.top
 * contributor: yumusb(https://github.com/yumusb)
 */

include "lib/class.geetestlib.php";
include_once "includes/XCaptcha_Config.php";
include_once "includes/XCaptcha_Validator.php";
include_once "includes/XCaptcha_Render.php";
include_once "includes/XCaptcha_Settings.php";
include_once "includes/XCaptcha_Mailer.php";

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
        // 后台「设置」菜单下的新设置页
        XCaptcha_Settings::registerAdmin();

        return _t('插件已经激活，设置入口在后台「设置 → XCaptcha」');
    }

    /**
     * Deactivate the plugin
     */
    public static function deactivate()
    {
        Helper::removeAction("xcaptcha");
        XCaptcha_Settings::unregisterAdmin();
        // 找回密码的数据表保留，重新启用后接着用
        XCaptcha_Passport::removeRoutes();
    }
    /**
     * Config panel
     */
    public static function config(Typecho_Widget_Helper_Form $form)
    {
        XCaptcha_Config::config($form);
    }

    /**
     * 原生设置页保存配置时由 Typecho 调用（启用插件时不会调）。
     * 找回密码的路由、数据表和新设置页在这里同步：禁用再启用插件会丢配置，所以不能只放在 activate 里。
     * @param array $settings 本次提交的配置
     * @return string|null 要显示的提示；返回 null 时 Typecho 显示默认的「插件设置已经保存」
     */
    public static function configCheck($settings)
    {
        try {
            $notes = XCaptcha_Settings::sync($settings);
        } catch (Exception $e) {
            return _t('插件设置已经保存，但找回密码的数据表或路由没能建好：%s', $e->getMessage());
        }
        if (!isset($settings['passportEnabled']) || (string) $settings['passportEnabled'] !== '1') {
            return null;
        }

        // 数字项填错时运行时会用默认值，这里提醒一下
        $ranges = [
            'passportSmtpPort' => [_t('端口'), 1, 65535, 465],
            'passportTokenTtl' => [_t('重置链接有效期'), 1, 1440, 60],
            'passportMailCooldown' => [_t('同一账号两次发信最短间隔'), 0, 1440, 5],
            'passportRateLimitAttempts' => [_t('10 分钟内最多提交次数'), 1, 1000, 20],
            'passportRateLimitLock' => [_t('超出后封禁时长'), 1, 1440, 5],
            'passportPasswordMinLength' => [_t('新密码最短长度'), 1, 64, 8],
        ];
        foreach ($ranges as $name => list($label, $min, $max, $default)) {
            $raw = trim((string) ($settings[$name] ?? ''));
            if (!preg_match('/^\d+$/', $raw) || (int) $raw < $min || (int) $raw > $max) {
                $notes[] = _t('「%s」要填 %d–%d 的整数，现在按默认值 %d 处理', $label, $min, $max, $default);
            }
        }

        $url = Typecho_Common::url(XCaptcha_Passport::PATH_FORGOT, Helper::options()->index);
        $message = _t('插件设置已经保存，找回密码页：%s', $url);
        return $notes ? $message . '；' . implode('；', $notes) : $message;
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
     * @param XCaptcha_Config $config 主配置或找回密码的配置档
     * @return boolean
     */
    public static function verifySubmission(XCaptcha_Config $config)
    {
        switch ($config->getCaptchaChoosen()) {
            case "geetest":
                return (bool) XCaptcha_Validator::verifyGeetest($config);
            case "altcha":
                return XCaptcha_Validator::verifyAltchaSolution($config);
            case "cap":
                return XCaptcha_Validator::verifyCapToken($config);
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
                "cap" => "Cap:Invalid verification code.",
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
        XCaptcha_Render::renderLoginLink($config);
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
     * @param string $profile 配置档：找回密码页「单独配置」了极验证时为 passport
     */
    public static function responseGeetest($profile = '')
    {
        $config = new XCaptcha_Config($profile === XCaptcha_Config::PROFILE_PASSPORT ? $profile : '');
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
