<?php

/**
 * 插件配置的字段清单、校验和保存。
 * 后台的新设置页（panel.php）保存时走这里；原生设置页的表单仍由 XCaptcha_Config::config 生成，
 * 两边字段名和默认值必须一致（测试里核对）。
 */
class XCaptcha_Settings
{
    /** 插件版本，和 Plugin.php 头部的 @version 一致 */
    const VERSION = '1.5.0';

    /** 新设置页所在的后台菜单：4 = 设置 */
    const PANEL_MENU = 4;
    const PANEL_FILE = 'XCaptcha/panel.php';
    const ADMIN_ACTION = 'xcaptcha-admin';

    /** 新设置页的表单和接口用的安全令牌后缀，令牌本身含当前用户的 authCode，别人算不出来 */
    const TOKEN_SUFFIX = 'xcaptcha-admin';

    /**
     * 字段清单
     * type: set（多选）/ bool / enum / text / secret（留空不修改）/ url / email / int / html
     * @return array
     */
    public static function fields()
    {
        $tools = XCaptcha_Config::TOOLS;
        return [
            'pages' => ['type' => 'set', 'options' => ['comments', 'login'], 'default' => []],
            'isAuthorUncheck' => ['type' => 'bool', 'default' => '0'],
            'captchaChoosen' => ['type' => 'enum', 'options' => $tools, 'default' => 'hcaptcha'],
            'captchaId' => ['type' => 'text', 'max' => 200, 'default' => ''],
            'secretKey' => ['type' => 'secret', 'max' => 500, 'default' => ''],
            'widgetColor' => ['type' => 'enum', 'options' => ['auto', 'light', 'dark'], 'default' => 'auto'],
            'widgetSize' => ['type' => 'enum', 'options' => ['normal', 'flexible', 'compact'], 'default' => 'normal'],
            'cdnUrl' => ['type' => 'url', 'default' => ''],
            'verifyUrl' => ['type' => 'url', 'default' => ''],
            'capEndpoint' => ['type' => 'url', 'default' => ''],
            'dismod' => ['type' => 'enum', 'options' => ['float', 'embed', 'popup'], 'default' => 'float'],

            'passportEnabled' => ['type' => 'bool', 'default' => '0'],
            'passportLoginLink' => ['type' => 'bool', 'default' => '1'],
            'passportCaptcha' => ['type' => 'enum', 'options' => ['follow', 'custom', 'none'], 'default' => 'follow'],
            'passportCaptchaChoosen' => ['type' => 'enum', 'options' => $tools, 'default' => 'altcha'],
            'passportCaptchaId' => ['type' => 'text', 'max' => 200, 'default' => ''],
            'passportSecretKey' => ['type' => 'secret', 'max' => 500, 'default' => ''],
            'passportCdnUrl' => ['type' => 'url', 'default' => ''],
            'passportVerifyUrl' => ['type' => 'url', 'default' => ''],
            'passportCapEndpoint' => ['type' => 'url', 'default' => ''],

            'passportSmtpHost' => ['type' => 'text', 'max' => 255, 'default' => ''],
            'passportSmtpPort' => ['type' => 'int', 'min' => 1, 'max' => 65535, 'default' => '465'],
            'passportSmtpSecure' => ['type' => 'enum', 'options' => ['ssl', 'tls', 'none'], 'default' => 'ssl'],
            'passportSmtpUser' => ['type' => 'text', 'max' => 255, 'default' => ''],
            'passportSmtpPassword' => ['type' => 'secret', 'max' => 500, 'default' => ''],
            'passportMailFrom' => ['type' => 'email', 'default' => ''],
            'passportMailFromName' => ['type' => 'text', 'max' => 100, 'default' => ''],
            'passportMailSubject' => ['type' => 'text', 'max' => 200, 'default' => '{sitename} - 密码重置'],
            'passportMailTemplate' => ['type' => 'html', 'max' => 20000, 'default' => XCaptcha_Config::defaultMailTemplate()],

            'passportTokenTtl' => ['type' => 'int', 'min' => 1, 'max' => 1440, 'default' => '60'],
            'passportMailCooldown' => ['type' => 'int', 'min' => 0, 'max' => 1440, 'default' => '5'],
            'passportHmacKey' => ['type' => 'secret', 'max' => 500, 'default' => '', 'clearable' => true],
            'passportRateLimit' => ['type' => 'bool', 'default' => '1'],
            'passportRateLimitAttempts' => ['type' => 'int', 'min' => 1, 'max' => 1000, 'default' => '20'],
            'passportRateLimitLock' => ['type' => 'int', 'min' => 1, 'max' => 1440, 'default' => '5'],
            'passportPasswordComplexity' => ['type' => 'bool', 'default' => '1'],
            'passportPasswordMinLength' => ['type' => 'int', 'min' => 1, 'max' => 64, 'default' => '8'],
        ];
    }

    /**
     * 当前保存的配置，没保存过的字段用默认值补上
     * @return array
     */
    public static function current()
    {
        $stored = [];
        try {
            $options = Helper::options()->plugin('XCaptcha');
            foreach (self::fields() as $name => $spec) {
                $stored[$name] = $options->$name;
            }
        } catch (Exception $e) {
        }
        $values = [];
        foreach (self::fields() as $name => $spec) {
            $values[$name] = isset($stored[$name]) ? $stored[$name] : $spec['default'];
        }
        return $values;
    }

    /**
     * 校验新设置页提交的整张表单
     * @param array $input   提交的值（字段名 => 值）；勾选框、开关没勾时不出现
     * @param array $current 当前保存的配置
     * @return array [清洗后的完整配置, 错误（字段名 => 提示）]
     */
    public static function sanitize(array $input, array $current)
    {
        $clean = [];
        $errors = [];
        foreach (self::fields() as $name => $spec) {
            $raw = array_key_exists($name, $input) ? $input[$name] : null;
            switch ($spec['type']) {
                case 'set':
                    $list = is_array($raw) ? $raw : [];
                    $clean[$name] = array_values(array_unique(array_filter($list, function ($v) use ($spec) {
                        return is_string($v) && in_array($v, $spec['options'], true);
                    })));
                    break;
                case 'bool':
                    $clean[$name] = (is_string($raw) && $raw === '1') ? '1' : '0';
                    break;
                case 'enum':
                    if (is_string($raw) && in_array($raw, $spec['options'], true)) {
                        $clean[$name] = $raw;
                    } else {
                        $errors[$name] = _t('请选择一项');
                        $clean[$name] = $current[$name];
                    }
                    break;
                case 'int':
                    $text = is_string($raw) ? trim($raw) : '';
                    if (!preg_match('/^\d{1,6}$/', $text) || (int) $text < $spec['min'] || (int) $text > $spec['max']) {
                        $errors[$name] = _t('请填 %d–%d 的整数', $spec['min'], $spec['max']);
                        $clean[$name] = $current[$name];
                    } else {
                        $clean[$name] = (string) (int) $text;
                    }
                    break;
                case 'url':
                    $text = is_string($raw) ? trim($raw) : '';
                    if ($text !== '' && !self::isHttpUrl($text)) {
                        $errors[$name] = _t('请填以 https:// 或 http:// 开头的完整地址');
                    }
                    $clean[$name] = $text;
                    break;
                case 'email':
                    $text = is_string($raw) ? trim($raw) : '';
                    if ($text !== '' && filter_var($text, FILTER_VALIDATE_EMAIL) === false) {
                        $errors[$name] = _t('邮箱格式不对');
                    }
                    $clean[$name] = $text;
                    break;
                case 'secret':
                    $text = is_string($raw) ? trim($raw) : '';
                    if (!empty($spec['clearable']) && isset($input['clear'][$name]) && $input['clear'][$name] === '1') {
                        $clean[$name] = '';
                    } elseif ($text === '') {
                        // 留空表示不修改，页面上不会回显已保存的密钥
                        $clean[$name] = (string) $current[$name];
                    } else {
                        $clean[$name] = $text;
                    }
                    if (strlen($clean[$name]) > $spec['max'] || preg_match('/[\x00-\x1f\x7f]/', $clean[$name])) {
                        $errors[$name] = _t('内容不对');
                    }
                    break;
                case 'html':
                    $text = is_string($raw) ? str_replace("\r\n", "\n", $raw) : '';
                    if (strlen($text) > $spec['max']) {
                        $errors[$name] = _t('太长了');
                    }
                    $clean[$name] = trim($text) === '' ? $spec['default'] : $text;
                    break;
                default: // text
                    $text = is_string($raw) ? trim($raw) : '';
                    if (preg_match('/[\x00-\x1f\x7f]/', $text)) {
                        $errors[$name] = _t('不能包含换行等控制字符');
                    } elseif (mb_strlen($text, 'UTF-8') > $spec['max']) {
                        $errors[$name] = _t('最多 %d 个字', $spec['max']);
                    }
                    $clean[$name] = $text;
            }
        }
        if ($clean['passportMailSubject'] === '') {
            $clean['passportMailSubject'] = self::fields()['passportMailSubject']['default'];
        }
        return [$clean, $errors];
    }

    /**
     * @param string $url
     * @return boolean 是 http(s) 的完整地址
     */
    public static function isHttpUrl($url)
    {
        if (strlen($url) > 500 || preg_match('/\s/', $url)) {
            return false;
        }
        $parts = parse_url($url);
        return is_array($parts) && isset($parts['scheme'], $parts['host'])
            && in_array(strtolower($parts['scheme']), ['http', 'https'], true);
    }

    /**
     * 保存配置并同步找回密码的路由、数据表和后台页面
     * @param array $settings 完整配置
     * @return array 提醒（字符串列表）
     */
    public static function save(array $settings)
    {
        Widget_Plugins_Edit::configPlugin('XCaptcha', $settings);
        return self::sync($settings);
    }

    /**
     * 按配置注册或移除找回密码的路由、建表，并确保新设置页已注册。保存配置时调用，可重复调用。
     * @param array $settings 本次保存的配置
     * @return array 提醒（字符串列表）
     * @throws Exception 建表或写路由失败
     */
    public static function sync(array $settings)
    {
        self::registerAdmin();

        $enabled = isset($settings['passportEnabled']) && (string) $settings['passportEnabled'] === '1';
        if (!$enabled) {
            XCaptcha_Passport::removeRoutes();
            return [];
        }
        XCaptcha_Passport::createTables();
        XCaptcha_Passport::addRoutes();
        return self::notes($settings);
    }

    /**
     * 注册新设置页和它用的接口（先移除再添加，重复调用不会出现两个菜单）
     */
    public static function registerAdmin()
    {
        Helper::addAction(self::ADMIN_ACTION, 'XCaptcha_Admin');
        Helper::removePanel(self::PANEL_MENU, self::PANEL_FILE);
        Helper::addPanel(self::PANEL_MENU, self::PANEL_FILE, 'XCaptcha', _t('验证码与找回密码'), 'administrator');
    }

    public static function unregisterAdmin()
    {
        Helper::removeAction(self::ADMIN_ACTION);
        Helper::removePanel(self::PANEL_MENU, self::PANEL_FILE);
    }

    /**
     * 新设置页的地址
     * @return string
     */
    public static function panelUrl()
    {
        // 和 addPanel 登记的菜单地址写法一致（斜杠编码），Typecho 才认得出当前菜单
        return Typecho_Common::url('extending.php?panel=' . urlencode(self::PANEL_FILE), Helper::options()->adminUrl);
    }

    /**
     * 找回密码已开启时，配置里值得提醒的问题
     * @param array $settings
     * @return array 提醒（字符串列表）
     */
    public static function notes(array $settings)
    {
        $notes = [];
        $plugins = Typecho_Plugin::export();
        if (isset($plugins['activated']['Passport'])) {
            $notes[] = _t('Passport 插件仍在启用，它的找回密码页面会优先生效，请先禁用它');
        }
        if (trim((string) ($settings['passportSmtpHost'] ?? '')) === '') {
            $notes[] = _t('还没填 SMTP 服务器，重置邮件发不出去');
        }
        $mode = (string) ($settings['passportCaptcha'] ?? 'follow');
        if ($mode === 'none') {
            $notes[] = _t('找回密码没有验证码，只靠频率限制挡机器人');
        } elseif ($mode === 'custom') {
            $tool = (string) ($settings['passportCaptchaChoosen'] ?? '');
            $missing = !in_array($tool, XCaptcha_Config::TOOLS, true)
                || ($tool === 'cap' && trim((string) ($settings['passportCapEndpoint'] ?? '')) === '')
                || ($tool !== 'altcha' && (trim((string) ($settings['passportCaptchaId'] ?? '')) === '' || trim((string) ($settings['passportSecretKey'] ?? '')) === ''));
            if ($missing) {
                $notes[] = _t('找回密码单独配置的验证码还不完整，在补全之前找回密码页会拒绝所有提交');
            }
        }
        return $notes;
    }
}
