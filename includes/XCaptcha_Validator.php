<?php

include_once 'XCaptcha_Request.php';
include_once 'XCaptcha_Utils.php';

/**
 * 校验验证码
 * @author CairBin(Xinyi Liu)
 */
class XCaptcha_Validator
{
    /** Altcha 题目有效期（秒），写评论可能要一阵子，给宽一点 */
    const ALTCHA_TTL = 1800;

    /** 每个会话最多同时保留的未用题目数，防止反复刷题撑大 session */
    const ALTCHA_MAX_PENDING = 20;

    /** Session 中保存 Altcha 题目的键：challenge => [hmacKey, expires] */
    const ALTCHA_SESSION_KEY = 'xcaptcha_altcha';

    /**
     * 保存一道刚出的 Altcha 题目（调用前需已 session_start）
     * @static
     * @param string $challenge 题目哈希
     * @param string $hmacKey   这道题的签名密钥
     * @param int    $expires   过期时间戳
     */
    public static function storeAltchaChallenge($challenge, $hmacKey, $expires)
    {
        $now = time();
        $pending = $_SESSION[self::ALTCHA_SESSION_KEY] ?? [];
        if (!is_array($pending)) {
            $pending = [];
        }
        $pending = array_filter($pending, function ($entry) use ($now) {
            return is_array($entry) && ($entry['expires'] ?? 0) >= $now;
        });
        $pending[$challenge] = ['key' => $hmacKey, 'expires' => $expires];
        // 超出上限时丢掉最早出的题（数组按插入顺序）
        $_SESSION[self::ALTCHA_SESSION_KEY] = array_slice($pending, -self::ALTCHA_MAX_PENDING, null, true);
    }

    /**
     * 极验证二次校验
     * @param Widget_Options::plugin $filter XCaptcha插件对象
     * @return false
     */    
    public static function verifyGeetest(XCaptcha_Config $config)
    {
        if (!isset($_POST['geetest_challenge']) || !isset($_POST['geetest_validate']) || !isset($_POST['geetest_seccode'])) {
            return 0;
        }

        @session_start();
        $geetestSdk = new GeetestLib($config->getCaptchaId(), $config->getSecretKey());
        if (!empty($_SESSION['gt_server_ok'])) {
            $widgetRequest = Typecho_Widget::widget('Widget_Options')->request;
            $agent = $widgetRequest->getAgent();
            $clientType = XCaptcha_Utils::isMobile($agent) ? 'h5' : 'web';
            $ipAddress = $widgetRequest->getIp();

            $data = array(
                'user_id' => $_SESSION['gt_user_id'],
                'client_type' => $clientType,
                'ip_address' => $ipAddress
            );

            return $geetestSdk->success_validate($_POST['geetest_challenge'], $_POST['geetest_validate'], $_POST['geetest_seccode'], $data);
        }
        return $geetestSdk->fail_validate($_POST['geetest_challenge'], $_POST['geetest_validate'], $_POST['geetest_seccode']);

    }

    /**
     * 验证Altcha
     * @static
     * @param  XCaptcha_Config $config  配置
     * @return boolean                  验证是否通过
     */    
    public static function verifyAltchaSolution(XCaptcha_Config $config){

        require_once dirname(__DIR__) . "/lib/class.altcha.php";

        $payload = $_POST['altcha'] ?? '';
        if (!is_string($payload) || $payload === '') {
            return false;
        }
        $decoded = json_decode((string) base64_decode($payload, true), true);
        $challenge = is_array($decoded) ? ($decoded['challenge'] ?? null) : null;
        if (!is_string($challenge)) {
            return false;
        }

        // 只认本会话出过、还没用过的题；无论对错都作废，一题只能交一次
        @session_start();
        $entry = $_SESSION[self::ALTCHA_SESSION_KEY][$challenge] ?? null;
        unset($_SESSION[self::ALTCHA_SESSION_KEY][$challenge]);
        if (!is_array($entry) || ($entry['expires'] ?? 0) < time()) {
            return false;
        }

        return \AltchaOrg\Altcha\Altcha::verifySolution($payload, $entry['key']);
    }


    /**
     * 验证 Cap：把组件交上来的 token 发给 Cap Standalone 的 siteverify 核对，token 由服务端保证只能用一次
     * @static
     * @param  XCaptcha_Config $config  配置
     * @return boolean                  验证是否通过
     */
    public static function verifyCapToken(XCaptcha_Config $config)
    {
        $token = $_POST['cap-token'] ?? '';
        if (!is_string($token) || $token === '') {
            return false;
        }

        $verifyUrl = $config->getVerifyUrl();
        if ($verifyUrl == '') {
            $verifyUrl = $config->getCapApiEndpoint() . 'siteverify';
        }
        $result = XCaptcha_Request::makeJsonRequest($verifyUrl, [
            'secret' => $config->getSecretKey(),
            'response' => $token,
        ]);

        return is_array($result) && ($result['success'] ?? false) === true;
    }

    /**
     * 验证其他Captcha
     * @static
     * @param  XCaptcha_Config $config  配置
     * @return boolean                  验证是否通过
     */    
    public static function verifyOtherCaptcha(XCaptcha_Config $config)
    {
        list($postToken, $urlPath) = XCaptcha_Utils::getCaptchaTokenAndUrl($config->getCaptchaChoosen(), $config->getVerifyUrl());
        if ($postToken === '') {
            return false;
        }
        $responseData = XCaptcha_Request::makeCaptchaRequest($urlPath, $config->getSecretKey(), $postToken);

        // 请求失败或返回的不是 JSON 时 $responseData 为 null，按未通过处理
        return is_object($responseData) && !empty($responseData->success);
    }
}