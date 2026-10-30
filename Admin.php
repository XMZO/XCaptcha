<?php
if (!defined('__TYPECHO_ROOT_DIR__')) exit;

// 设置、校验等类在 Plugin.php 里引入；平时 index.php:begin 钩子已经加载过，这里不依赖它
class_exists('XCaptcha_Plugin');

/**
 * 新设置页（panel.php）用的接口：/action/xcaptcha-admin?do=save|testCap|testMail|unblock
 * 只接受管理员的 POST，并核对页面带上的安全令牌；返回 JSON。
 * 页面脚本没加载时表单直接提交过来，保存后跳回设置页并用 Typecho 的提示显示结果。
 */
class XCaptcha_Admin extends Typecho_Widget implements Widget_Interface_Do
{
    /** 检测 Cap 时用的假 token：格式合法、肯定不存在，Cap 核对完密钥后会回「Token not found」 */
    const CAP_PROBE_TOKEN = ':xcaptcha:selftest';

    public function execute()
    {
    }

    public function action()
    {
        if (!$this->request->isPost()) {
            $this->reply(405, ['ok' => false, 'message' => _t('只接受 POST 请求')]);
        }
        if (!Typecho_Widget::widget('Widget_User')->pass('administrator', true)) {
            $this->reply(403, ['ok' => false, 'message' => _t('没有权限，请重新登录')]);
        }
        $token = isset($_POST['_']) && is_string($_POST['_']) ? $_POST['_'] : '';
        $expected = Typecho_Widget::widget('Widget_Security')->getToken(XCaptcha_Settings::TOKEN_SUFFIX);
        if (!hash_equals($expected, $token)) {
            $this->reply(403, ['ok' => false, 'message' => _t('安全令牌不对，请刷新页面后重试')]);
        }

        switch ((string) $this->request->get('do')) {
            case 'save':
                $this->save();
                break;
            case 'testCap':
                $this->testCap();
                break;
            case 'testMail':
                $this->testMail();
                break;
            case 'unblock':
                $this->unblock();
                break;
            default:
                $this->reply(400, ['ok' => false, 'message' => _t('未知操作')]);
        }
    }

    /**
     * 保存整张表单；有一项不合格就一项都不存
     */
    private function save()
    {
        if (!isset($_POST['xc_form'])) {
            $this->reply(400, ['ok' => false, 'message' => _t('表单不完整，请刷新页面后重试')]);
        }
        list($clean, $errors) = XCaptcha_Settings::sanitize($_POST, XCaptcha_Settings::current());
        if ($errors) {
            $this->reply(422, ['ok' => false, 'message' => _t('有 %d 处需要修改，没有保存', count($errors)), 'errors' => $errors]);
        }
        try {
            $notes = XCaptcha_Settings::save($clean);
        } catch (Exception $e) {
            $this->reply(500, ['ok' => false, 'message' => _t('保存失败：%s', $e->getMessage())]);
        }

        $secrets = [];
        foreach (XCaptcha_Settings::fields() as $name => $spec) {
            if ($spec['type'] === 'secret') {
                $secrets[$name] = $clean[$name] !== '';
            }
        }
        $this->reply(200, ['ok' => true, 'message' => _t('设置已保存'), 'notes' => $notes, 'secrets' => $secrets]);
    }

    /**
     * 从博客服务器检测 Cap 服务：出题接口、组件资源、站点 key 和密钥
     */
    private function testCap()
    {
        $current = XCaptcha_Settings::current();
        $passport = $this->post('profile') === 'passport';
        $endpoint = rtrim($this->post('endpoint'), '/');
        $siteKey = $this->post('siteKey');
        $secret = $this->post('secret');
        $cdnUrl = $this->post('cdnUrl');
        $verifyUrl = $this->post('verifyUrl');

        if (!XCaptcha_Settings::isHttpUrl($endpoint)) {
            $this->reply(422, ['ok' => false, 'message' => _t('先填 Cap 服务地址')]);
        }
        if (!preg_match('/^[A-Za-z0-9_-]{1,100}$/', $siteKey)) {
            $this->reply(422, ['ok' => false, 'message' => _t('站点 key 格式不对')]);
        }
        $usedSaved = false;
        if ($secret === '') {
            $secret = (string) $current[$passport ? 'passportSecretKey' : 'secretKey'];
            $usedSaved = true;
        }

        $checks = [];
        $api = $endpoint . '/' . rawurlencode($siteKey) . '/';

        $res = $this->http($api . 'challenge', '{}');
        if ($res['error'] !== null) {
            $checks[] = [false, _t('出题接口连不上'), $res['error']];
        } elseif ($res['code'] === 200 && is_array(json_decode($res['body'], true))) {
            $checks[] = [true, _t('出题接口正常'), _t('%d 毫秒', $res['ms'])];
        } elseif ($res['code'] === 404) {
            $checks[] = [false, _t('站点 key 不存在'), _t('在 Cap 后台核对站点 key')];
        } elseif ($res['code'] === 403) {
            $checks[] = [false, _t('出题被拒绝'), _t('博客服务器的 IP 可能被 Cap 封禁了')];
        } else {
            $checks[] = [false, _t('出题接口异常'), 'HTTP ' . $res['code']];
        }

        if ($cdnUrl === '') {
            $missing = [];
            foreach (['widget.js', 'cap_wasm_bg.wasm'] as $file) {
                $asset = $this->http($endpoint . '/assets/' . $file);
                if ($asset['error'] !== null || $asset['code'] !== 200) {
                    $missing[] = $file;
                }
            }
            $checks[] = $missing
                ? [false, _t('组件资源取不到'), _t('%s；Cap 服务端要设置 ENABLE_ASSETS_SERVER=true', implode('、', $missing))]
                : [true, _t('组件资源齐全'), 'widget.js、cap_wasm_bg.wasm'];
        } elseif (XCaptcha_Settings::isHttpUrl($cdnUrl)) {
            $asset = $this->http($cdnUrl);
            $checks[] = ($asset['error'] === null && $asset['code'] === 200)
                ? [true, _t('组件脚本可用'), $cdnUrl]
                : [false, _t('组件脚本取不到'), $asset['error'] !== null ? $asset['error'] : 'HTTP ' . $asset['code']];
        } else {
            $checks[] = [false, _t('验证码JS地址格式不对'), $cdnUrl];
        }

        if ($secret === '') {
            $checks[] = [false, _t('没有密钥'), _t('填上站点 key 的密钥')];
        } else {
            $url = XCaptcha_Settings::isHttpUrl($verifyUrl) ? $verifyUrl : $api . 'siteverify';
            $res = $this->http($url, json_encode(['secret' => $secret, 'response' => $siteKey . self::CAP_PROBE_TOKEN]));
            $data = json_decode((string) $res['body'], true);
            $error = is_array($data) && isset($data['error']) ? (string) $data['error'] : '';
            if ($res['error'] !== null) {
                $checks[] = [false, _t('校验接口连不上'), $res['error']];
            } elseif ($error === 'Token not found') {
                $checks[] = [true, _t('密钥正确'), $usedSaved ? _t('用的是已保存的密钥') : ''];
            } elseif ($error === 'Invalid site key or secret') {
                $checks[] = [false, _t('密钥不对'), _t('不是站点 key 的密钥，或者和站点 key 不配套')];
            } else {
                $checks[] = [false, _t('校验接口返回异常'), 'HTTP ' . $res['code'] . ($error !== '' ? ' · ' . $error : '')];
            }
        }

        $ok = true;
        $list = [];
        foreach ($checks as list($pass, $label, $detail)) {
            $ok = $ok && $pass;
            $list[] = ['ok' => $pass, 'label' => $label, 'detail' => $detail];
        }
        $this->reply(200, ['ok' => $ok, 'message' => $ok ? _t('Cap 服务正常') : _t('Cap 服务有问题'), 'checks' => $list]);
    }

    /**
     * 用页面上填的 SMTP（还没保存也行）给当前管理员发一封测试邮件
     */
    private function testMail()
    {
        $current = XCaptcha_Settings::current();
        $input = $current;
        foreach (['passportSmtpHost', 'passportSmtpPort', 'passportSmtpSecure', 'passportSmtpUser', 'passportSmtpPassword', 'passportMailFrom', 'passportMailFromName'] as $name) {
            $input[$name] = $this->post($name);
        }
        $input['xc_form'] = '1';
        list($clean, $errors) = XCaptcha_Settings::sanitize($input, $current);
        $smtpErrors = array_intersect_key($errors, array_flip(['passportSmtpHost', 'passportSmtpPort', 'passportSmtpSecure', 'passportSmtpUser', 'passportSmtpPassword', 'passportMailFrom', 'passportMailFromName']));
        if ($smtpErrors) {
            $this->reply(422, ['ok' => false, 'message' => _t('先改好邮件设置里标出的地方'), 'errors' => $smtpErrors]);
        }

        $user = Typecho_Widget::widget('Widget_User');
        $to = (string) $user->mail;
        if ($to === '') {
            $this->reply(422, ['ok' => false, 'message' => _t('你的账号没有填邮箱，在「个人设置」里填上再试')]);
        }

        $options = Helper::options();
        $siteName = (string) $options->title;
        $smtp = [
            'host' => $clean['passportSmtpHost'],
            'port' => (int) $clean['passportSmtpPort'],
            'secure' => $clean['passportSmtpSecure'],
            'user' => $clean['passportSmtpUser'],
            'password' => $clean['passportSmtpPassword'],
            'from' => $clean['passportMailFrom'],
            'fromName' => $clean['passportMailFromName'] !== '' ? $clean['passportMailFromName'] : $siteName,
        ];
        $time = (new Typecho_Date(time()))->format('Y-m-d H:i:s');
        $e = function ($text) {
            return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8');
        };
        $html = '<p>' . $e(_t('这是 XCaptcha 发出的测试邮件，说明找回密码的 SMTP 设置可以正常发信。')) . '</p>'
            . '<p>' . $e(_t('站点：%s', $siteName)) . '<br>' . $e(_t('发送时间：%s', $time)) . '<br>'
            . $e(_t('SMTP：%s:%d', $smtp['host'], $smtp['port'])) . '</p>';
        $subject = _t('%s - XCaptcha 测试邮件', $siteName);

        $started = microtime(true);
        $error = XCaptcha_Mailer::send($smtp, $to, (string) $user->screenName, $subject, $html, XCaptcha_Mailer::toText($html));
        $ms = (int) round((microtime(true) - $started) * 1000);
        if ($error !== null) {
            $this->reply(200, ['ok' => false, 'message' => _t('发送失败：%s', $error)]);
        }
        $this->reply(200, ['ok' => true, 'message' => _t('已发到 %s，用时 %.1f 秒', self::maskMail($to), $ms / 1000)]);
    }

    private function unblock()
    {
        $ip = $this->post('ip');
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            $this->reply(422, ['ok' => false, 'message' => _t('IP 地址无效')]);
        }
        try {
            $found = XCaptcha_Passport::unblockIp($ip);
        } catch (Typecho_Db_Exception $e) {
            $this->reply(500, ['ok' => false, 'message' => _t('解封失败：%s', $e->getMessage())]);
        }
        $this->reply(200, ['ok' => true, 'message' => $found ? _t('已解封 %s', $ip) : _t('没有 %s 的记录', $ip)]);
    }

    /**
     * 取一个字符串参数（去掉首尾空白），不是字符串时为空
     */
    private function post($name)
    {
        return isset($_POST[$name]) && is_string($_POST[$name]) ? trim($_POST[$name]) : '';
    }

    /**
     * 从博客服务器发一个 HTTP 请求；有请求体时为 JSON POST，否则 GET
     * @return array code, body, ms, error（连不上时的原因，否则 null）
     */
    private function http($url, $json = null)
    {
        $ch = curl_init($url);
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_USERAGENT => 'XCaptcha (Typecho)',
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
        ];
        if (defined('CURLOPT_PROTOCOLS')) {
            $opts[CURLOPT_PROTOCOLS] = CURLPROTO_HTTP | CURLPROTO_HTTPS;
        }
        if ($json !== null) {
            $opts[CURLOPT_POST] = true;
            $opts[CURLOPT_POSTFIELDS] = $json;
            $opts[CURLOPT_HTTPHEADER] = ['Content-Type: application/json', 'Accept: application/json'];
        }
        curl_setopt_array($ch, $opts);
        $started = microtime(true);
        $body = curl_exec($ch);
        $ms = (int) round((microtime(true) - $started) * 1000);
        $error = $body === false ? curl_error($ch) : null;
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ['code' => $code, 'body' => $body === false ? '' : $body, 'ms' => $ms, 'error' => $error];
    }

    /**
     * a***@example.com
     */
    public static function maskMail($mail)
    {
        $at = strpos($mail, '@');
        if ($at === false || $at < 1) {
            return $mail;
        }
        return mb_substr($mail, 0, 1, 'UTF-8') . '***' . substr($mail, $at);
    }

    /**
     * 回复 JSON；页面脚本没加载、表单直接提交时，改为带提示跳回设置页
     */
    private function reply($status, array $data)
    {
        $ajax = strtolower((string) $this->request->getHeader('X-Requested-With')) === 'xmlhttprequest';
        if (!$ajax && (string) $this->request->get('do') === 'save' && $status !== 405) {
            $notice = Typecho_Widget::widget('Widget_Notice');
            $messages = [$data['message']];
            foreach (isset($data['errors']) ? $data['errors'] : [] as $name => $error) {
                $messages[] = $name . '：' . $error;
            }
            foreach (isset($data['notes']) ? $data['notes'] : [] as $note) {
                $messages[] = $note;
            }
            $notice->set(array_map(function ($m) {
                return htmlspecialchars($m, ENT_QUOTES, 'UTF-8');
            }, $messages), $data['ok'] ? 'success' : 'error');
            $this->response->redirect(XCaptcha_Settings::panelUrl());
        }
        $this->response->setStatus($status);
        $this->response->setHeader('Cache-Control', 'no-store');
        $this->response->throwJson($data);
    }
}
