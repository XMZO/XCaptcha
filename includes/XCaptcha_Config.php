<?php

/**
 * 获取插件配置
 * @author CairBin(Xinyi Liu)
 */
class XCaptcha_Config{
    /** 找回密码页用的验证码配置档；主配置档为空字符串 */
    const PROFILE_PASSPORT = 'passport';

    /** 支持的验证工具 */
    const TOOLS = ['altcha', 'cap', 'hcaptcha', 'cloudflare', 'recaptcha', 'geetest'];

    protected $options;
    protected $geetest;

    /** @var string 当前配置档，见 PROFILE_* */
    protected $profile;

    /** @var string 读取验证码字段时加的前缀：主配置为空；找回密码「单独配置」时为 passport，即读 passportCaptchaId 等 */
    protected $prefix = '';

    /**
     * @param string $profile 配置档，留空为评论/登录用的主配置，PROFILE_PASSPORT 为找回密码页
     */
    public function __construct($profile = '')
    {
        $this->options = Helper::options()->plugin('XCaptcha');
        $this->profile = (string) $profile;
        if ($this->profile === self::PROFILE_PASSPORT && $this->getPassportCaptchaMode() === 'custom') {
            $this->prefix = self::PROFILE_PASSPORT;
        }
        $this->geetest = new XCaptcha_GeetestInfo(
            $this->options->dismod
        );
    }

    /**
     * 插件面板配置
     * @static
     * @param Typecho_Widget_Helper_Form $form 配置对象
     */
    public static function config(Typecho_Widget_Helper_Form $form)
    {
        $tools = [ "altcha" => "Altcha", "cap" => "Cap", "hcaptcha" => "hCaptcha", "cloudflare" => "Cloudflare", "recaptcha" => "Google reCaptcha v2", "geetest" => "极验证 v3"];

        $form->addInput(new Typecho_Widget_Helper_Form_Element_Checkbox('pages', ["comments" => "评论", "login" => "登陆页"], [], _t('应用到'), _t('在哪些页面应用验证码')));
        $form->addInput(new Typecho_Widget_Helper_Form_Element_Select('isAuthorUncheck', array(false => '关闭', true => '开启'), false, _t('管理员评论无需验证')));
        $form->addInput(new Typecho_Widget_Helper_Form_Element_Text('captchaId', NULL, '', _t('Captcha ID'), _t('公钥(ID)')));
        $form->addInput(new Typecho_Widget_Helper_Form_Element_Text('secretKey', NULL, '', _t('Secret Key'), _t('私钥(Key)')));
        $form->addInput(new Typecho_Widget_Helper_Form_Element_Radio('widgetColor', ["auto"=>"自动", "light" => "浅色", "dark" => "深色"], "auto", _t('颜色'), _t('设置验证工具主题颜色，默认为浅色<br/>- hCaptcha不支持自动<br/>- reCaptcha v2不支持自动<br/>- 极验证v3不支持颜色<br/>- altcha、Cap不支持配置，跟随页面文字颜色')));
        $form->addInput(new Typecho_Widget_Helper_Form_Element_Radio('widgetSize', ["normal" => "常规", "flexible"=>"灵活", "compact" => "紧凑"], "normal", _t('样式'), _t('设置验证框布局样式，默认为常规<br/>- hCaptcha不支持灵活<br/>- reCaptcha v2不支持灵活<br/>- altcha、Cap不支持配置')));
        $form->addInput(new Typecho_Widget_Helper_Form_Element_Radio('captchaChoosen', $tools, "hcaptcha", _t('验证工具'), _t('选择验证工具<br/>- altcha为纯本地方案，但是需要页面支持HTTPS才能使用<br/>- Cap需要自己部署的 Cap Standalone 服务，见下方「Cap 服务地址」')));
        $form->addInput(new Typecho_Widget_Helper_Form_Element_Text('cdnUrl', NULL, '', _t('验证码JS地址:'), _t('用于CDN加速加载验证码, 留空引入默认JS</br>注意使用 https 协议')));
        $form->addInput(new Typecho_Widget_Helper_Form_Element_Text('verifyUrl', NULL, '', _t('校验地址:'), _t('用于设置验证码校验接口, 留空使用默认, 此项不支持极验证')));
        $form->addInput(new Typecho_Widget_Helper_Form_Element_Text('capEndpoint', NULL, '', _t('Cap 服务地址:'), _t('仅 Cap 使用：Cap Standalone 的地址，如 https://cap.example.com，不含站点 key<br/>- Captcha ID 填站点 key，Secret Key 填该站点 key 的密钥（不是后台登录用的 ADMIN_KEY）<br/>- 验证码JS地址留空时，组件脚本和 WASM 都从该服务的 /assets/ 加载，需在服务端设置 ENABLE_ASSETS_SERVER=true<br/>- 校验地址留空时使用 服务地址/站点key/siteverify')));
        $form->addInput(new Typecho_Widget_Helper_Form_Element_Select('dismod', array('float' => '浮动式（float）', 'embed' => '嵌入式（embed）', 'popup' => '弹出框（popup）'), 'float', _t('极验证展现形式：')));

        // 找回密码（由 Passport 插件合并而来）
        $form->addInput(new Typecho_Widget_Helper_Form_Element_Radio('passportEnabled', ['0' => '关闭', '1' => '开启'], '0', _t('<h2>找回密码</h2>启用找回密码'), _t('开启后保存配置即注册页面 <code>/passport/forgot</code>、<code>/passport/reset</code> 并创建数据表，关闭则移除这两个地址<br/>- 原来用 Passport 插件的，请先在插件列表里禁用它（它的数据表会保留，这里直接接着用）<br/>- 邮件和安全设置见下方')));
        $form->addInput(new Typecho_Widget_Helper_Form_Element_Radio('passportLoginLink', ['1' => '显示', '0' => '不显示'], '1', _t('后台登录页显示「忘记密码」链接'), _t('显示在登录页「返回首页」旁边')));
        $form->addInput(new Typecho_Widget_Helper_Form_Element_Radio('passportCaptcha', ['follow' => '跟随上方的验证工具和密钥', 'custom' => '单独配置（用下面几项）', 'none' => '不用验证码（不推荐）'], 'follow', _t('找回密码使用的验证码'), _t('找回密码页一定会校验验证码，不受「应用到」的影响<br/>- 不用验证码时只剩按 IP 的频率限制在挡机器人')));
        $form->addInput(new Typecho_Widget_Helper_Form_Element_Radio('passportCaptchaChoosen', $tools, 'altcha', _t('找回密码验证工具'), _t('以下几项仅「单独配置」时生效；颜色、样式、极验证展现形式沿用上方设置')));
        $form->addInput(new Typecho_Widget_Helper_Form_Element_Text('passportCaptchaId', NULL, '', _t('找回密码 Captcha ID'), _t('公钥(ID)')));
        $form->addInput(new Typecho_Widget_Helper_Form_Element_Text('passportSecretKey', NULL, '', _t('找回密码 Secret Key'), _t('私钥(Key)')));
        $form->addInput(new Typecho_Widget_Helper_Form_Element_Text('passportCdnUrl', NULL, '', _t('找回密码验证码JS地址:'), _t('留空引入默认JS')));
        $form->addInput(new Typecho_Widget_Helper_Form_Element_Text('passportVerifyUrl', NULL, '', _t('找回密码校验地址:'), _t('留空使用默认')));
        $form->addInput(new Typecho_Widget_Helper_Form_Element_Text('passportCapEndpoint', NULL, '', _t('找回密码 Cap 服务地址:'), _t('仅 Cap 使用')));

        $form->addInput(new Typecho_Widget_Helper_Form_Element_Text('passportSmtpHost', NULL, '', _t('<h2>找回密码邮件（SMTP）</h2>服务器'), _t('如 smtp.exmail.qq.com')));
        $form->addInput(new Typecho_Widget_Helper_Form_Element_Text('passportSmtpPort', NULL, '465', _t('端口'), _t('常见：465（SSL）、587（STARTTLS）、25（不加密）')));
        $form->addInput(new Typecho_Widget_Helper_Form_Element_Select('passportSmtpSecure', ['ssl' => 'SSL', 'tls' => 'STARTTLS', 'none' => '不加密'], 'ssl', _t('加密方式')));
        $form->addInput(new Typecho_Widget_Helper_Form_Element_Text('passportSmtpUser', NULL, '', _t('帐号'), _t('SMTP 登录帐号，通常就是发件邮箱；留空则不登录（内网中继）')));
        $form->addInput(new Typecho_Widget_Helper_Form_Element_Password('passportSmtpPassword', NULL, '', _t('密码'), _t('SMTP 密码或授权码')));
        $form->addInput(new Typecho_Widget_Helper_Form_Element_Text('passportMailFrom', NULL, '', _t('发件人地址'), _t('留空用上面的帐号')));
        $form->addInput(new Typecho_Widget_Helper_Form_Element_Text('passportMailFromName', NULL, '', _t('发件人名称'), _t('留空用站点名称')));
        $form->addInput(new Typecho_Widget_Helper_Form_Element_Text('passportMailSubject', NULL, '{sitename} - 密码重置', _t('邮件标题'), _t('可用占位符：{sitename} {username}')));
        $form->addInput(new Typecho_Widget_Helper_Form_Element_Textarea('passportMailTemplate', NULL, self::defaultMailTemplate(), _t('邮件内容（HTML）'), _t('可用占位符：{username} {sitename} {siteUrl} {requestTime} {resetLink} {expireMinutes} {ip}；留空用默认模板')));

        $form->addInput(new Typecho_Widget_Helper_Form_Element_Text('passportTokenTtl', NULL, '60', _t('<h2>找回密码安全设置</h2>重置链接有效期（分钟）'), _t('1–1440，默认 60')));
        $form->addInput(new Typecho_Widget_Helper_Form_Element_Text('passportMailCooldown', NULL, '5', _t('同一账号两次发信最短间隔（分钟）'), _t('0–1440，默认 5；填 0 关闭。间隔内再申请不会再发邮件，也不会提示，防止被人用来轰炸邮箱')));
        $form->addInput(new Typecho_Widget_Helper_Form_Element_Text('passportHmacKey', NULL, '', _t('HMAC 密钥'), _t('给重置链接签名用；留空则由 Typecho 的站点密钥派生，一般不用填。改动后已发出的链接全部失效')));
        $form->addInput(new Typecho_Widget_Helper_Form_Element_Radio('passportRateLimit', ['1' => '启用', '0' => '禁用'], '1', _t('按 IP 限制请求频率'), _t('申请和重置的每次提交都计数，超出后临时封禁该 IP，下方可查看和解封')));
        $form->addInput(new Typecho_Widget_Helper_Form_Element_Text('passportRateLimitAttempts', NULL, '20', _t('10 分钟内最多提交次数'), _t('1–1000，默认 20')));
        $form->addInput(new Typecho_Widget_Helper_Form_Element_Text('passportRateLimitLock', NULL, '5', _t('超出后封禁时长（分钟）'), _t('1–1440，默认 5')));
        $form->addInput(new Typecho_Widget_Helper_Form_Element_Radio('passportPasswordComplexity', ['1' => '启用', '0' => '禁用'], '1', _t('新密码复杂度要求'), _t('启用后要求同时包含大小写字母、数字和特殊字符；禁用后只检查长度')));
        $form->addInput(new Typecho_Widget_Helper_Form_Element_Text('passportPasswordMinLength', NULL, '8', _t('新密码最短长度'), _t('1–64，默认 8')));

        // 只在后台配置页上输出封禁记录表；保存配置的 action 请求里也会构建这个表单，那时不能输出内容
        $request = Typecho_Widget::widget('Widget_Options')->request;
        if (stripos((string) $request->getRequestUrl(), 'options-plugin.php') !== false) {
            XCaptcha_Passport::handleUnblockRequest();
            echo XCaptcha_Passport::renderFailLog();
            echo self::configPageScript();
        }
    }

    /**
     * 默认的重置邮件模板
     * @static
     * @return string
     */
    public static function defaultMailTemplate()
    {
        return '<h3>{username}，您好：</h3>
<p>您于 {requestTime} 在 {sitename} 申请了重置密码。</p>
<p>请在 {expireMinutes} 分钟内打开下面的链接设置新密码：</p>
<p><a href="{resetLink}">{resetLink}</a></p>
<hr/>
<p>如果这不是您本人的操作，请忽略本邮件，您的密码不会改变。本次申请来自 IP：{ip}</p>';
    }

    /**
     * 配置页脚本：「单独配置」时才显示找回密码自己的验证码几项
     * @static
     * @return string
     */
    private static function configPageScript()
    {
        return <<<'EOF'
<script>
(function () {
    var names = ['passportCaptchaChoosen', 'passportCaptchaId', 'passportSecretKey', 'passportCdnUrl', 'passportVerifyUrl', 'passportCapEndpoint'];
    function row(name) {
        var input = document.querySelector('[name="' + name + '"]');
        return input ? input.closest('li') : null;
    }
    function update() {
        var checked = document.querySelector('input[name="passportCaptcha"]:checked');
        var show = checked && checked.value === 'custom';
        names.forEach(function (name) {
            var li = row(name);
            if (li) li.style.display = show ? '' : 'none';
        });
    }
    document.addEventListener('DOMContentLoaded', function () {
        var radios = document.querySelectorAll('input[name="passportCaptcha"]');
        for (var i = 0; i < radios.length; i++) radios[i].addEventListener('change', update);
        update();
    });
})();
</script>
EOF;
    }

    /**
     * 读取当前配置档的验证码字段：主配置直接读，找回密码「单独配置」时读带 passport 前缀的那份
     * @param string $name 字段名，如 captchaId
     * @return mixed
     */
    protected function field($name)
    {
        if ($this->prefix === '') {
            return $this->options->$name;
        }
        return $this->options->{$this->prefix . ucfirst($name)};
    }

    /**
     * 读取一个可能还没保存过的配置项（升级后新加的字段在旧配置里不存在）
     * @param string $name    字段名
     * @param mixed  $default 没保存过时的默认值
     * @return mixed
     */
    protected function opt($name, $default)
    {
        $value = $this->options->$name;
        return $value === null ? $default : $value;
    }

    /**
     * 读取整数配置项，不是范围内的整数就用默认值
     */
    protected function optInt($name, $default, $min, $max)
    {
        $raw = trim((string) $this->opt($name, $default));
        if (!preg_match('/^-?\d+$/', $raw)) {
            return $default;
        }
        $value = (int) $raw;
        return ($value < $min || $value > $max) ? $default : $value;
    }

    /**
     * 当前配置档
     * @return string 空字符串或 PROFILE_PASSPORT
     */
    public function getProfile()
    {
        return $this->profile;
    }

    /**
     * 找回密码页选择了「不用验证码」
     * @return boolean
     */
    public function isCaptchaDisabled()
    {
        return $this->profile === self::PROFILE_PASSPORT && $this->getPassportCaptchaMode() === 'none';
    }

    /**
     * 获取启用的页面
     * @return Array<string> 包含启用页面名称的数组
     */
    public function getPages()
    {
        $pages = $this->options->pages;
        return is_array($pages) ? $pages : [];
    }

    public function isAuthorUncheck()
    {
        return $this->options->isAuthorUncheck;
    }

    /**
     * 获取验证码 ID（公钥）
     * @return string 验证码 ID（公钥）
     */
    public function getCaptchaId()
    {
        return (string) $this->field('captchaId');
    }

    /**
     * 获取验证码私钥
     * @return string 验证码私钥
     */
    public function getSecretKey()
    {
        return (string) $this->field('secretKey');
    }

    /**
     * 判断验证码是否在当前页面启用
     * @param  string $page  页面名称
     * @return boolean       返回结果
     */
    public function isCaptchaEnabledOnPage($page)
    {
        return in_array($page, $this->getPages());
    }

    /**
     * 校验私钥、公钥是否正确（不为空，除非 getCaptchaChoosen() 返回 "altcha"）
     * @return boolean 返回结果
     */
    public function checkKeys()
    {
        $captchaChoosen = $this->getCaptchaChoosen();
        if (!in_array($captchaChoosen, self::TOOLS, true)) {
            return false; // 没选过验证工具（比如找回密码「单独配置」还没保存）
        }
        if ($captchaChoosen === "altcha") {
            return true; // 如果 getCaptchaChoosen 是 "altcha"，直接返回 true
        }

        $captchaId = $this->getCaptchaId();
        $secretKey = $this->getSecretKey();
        if ($captchaChoosen === "cap" && $this->getCapEndpoint() === '') {
            return false;
        }

        return !empty($captchaId) && !empty($secretKey);
    }

    /**
     * 获取验证码主题颜色配置（自动/明亮/暗黑）
     * @return string 颜色配置
     */
    public function getWidgetColor()
    {
        return $this->options->widgetColor;
    }

    /**
     * 获取验证码尺寸（灵活/宽松/紧凑）
     * @return string 尺寸
     */
    public function getwidgetSize()
    {
        return $this->options->widgetSize;
    }

    /**
     * 获取验证工具类型
     * @return string 类型
     */
    public function getCaptchaChoosen()
    {
        return (string) $this->field('captchaChoosen');
    }

    /**
     * 获取验证码加载脚本的CDN路径
     * @return string 路径
     */
    public function getCdnUrl()
    {
        return (string) $this->field('cdnUrl');
    }

    /**
     * 获取服务端校验接口
     * @return string 路径
     */
    public function getVerifyUrl()
    {
        return (string) $this->field('verifyUrl');
    }

    /**
     * 获取 Cap Standalone 服务地址（去掉末尾的 /）
     * @return string 服务地址，未填写时为空字符串
     */
    public function getCapEndpoint()
    {
        return rtrim(trim((string) $this->field('capEndpoint')), '/');
    }

    /**
     * 获取 Cap 组件的 API 地址：服务地址/站点key/
     * @return string API 地址
     */
    public function getCapApiEndpoint()
    {
        return $this->getCapEndpoint() . '/' . rawurlencode(trim((string) $this->getCaptchaId())) . '/';
    }

    /**
     * 获取Geetest专属的配置信息
     * @return XCaptcha_GeetestInfo 配置信息类
     */
    public function getGeetestConfig()
    {
        $this->geetest->dismod = $this->options->dismod;

        return $this->geetest;
    }

    /* ---------- 找回密码 ---------- */

    /**
     * 找回密码功能是否开启
     * @return boolean
     */
    public function isPassportEnabled()
    {
        return (string) $this->opt('passportEnabled', '0') === '1';
    }

    /**
     * 后台登录页是否显示「忘记密码」链接
     * @return boolean
     */
    public function isPassportLoginLinkEnabled()
    {
        return (string) $this->opt('passportLoginLink', '1') === '1';
    }

    /**
     * 找回密码页验证码的来源
     * @return string follow（跟随主配置）/ custom（单独配置）/ none（不用）
     */
    public function getPassportCaptchaMode()
    {
        $mode = (string) $this->opt('passportCaptcha', 'follow');
        return in_array($mode, ['follow', 'custom', 'none'], true) ? $mode : 'follow';
    }

    /**
     * SMTP 设置
     * @return array host, port, secure(ssl/tls/none), user, password, from, fromName
     */
    public function getPassportSmtp()
    {
        $secure = (string) $this->opt('passportSmtpSecure', 'ssl');
        return [
            'host' => trim((string) $this->opt('passportSmtpHost', '')),
            'port' => $this->optInt('passportSmtpPort', 465, 1, 65535),
            'secure' => in_array($secure, ['ssl', 'tls', 'none'], true) ? $secure : 'ssl',
            'user' => trim((string) $this->opt('passportSmtpUser', '')),
            'password' => (string) $this->opt('passportSmtpPassword', ''),
            'from' => trim((string) $this->opt('passportMailFrom', '')),
            'fromName' => trim((string) $this->opt('passportMailFromName', '')),
        ];
    }

    /**
     * 重置邮件标题模板
     * @return string
     */
    public function getPassportMailSubject()
    {
        $subject = trim((string) $this->opt('passportMailSubject', ''));
        return $subject === '' ? '{sitename} - 密码重置' : $subject;
    }

    /**
     * 重置邮件正文模板（HTML）
     * @return string
     */
    public function getPassportMailTemplate()
    {
        $template = (string) $this->opt('passportMailTemplate', '');
        return trim($template) === '' ? self::defaultMailTemplate() : $template;
    }

    /**
     * 重置链接有效期
     * @return int 秒
     */
    public function getPassportTokenTtl()
    {
        return $this->optInt('passportTokenTtl', 60, 1, 1440) * 60;
    }

    /**
     * 同一账号两次发信的最短间隔
     * @return int 秒，0 为不限制
     */
    public function getPassportMailCooldown()
    {
        return $this->optInt('passportMailCooldown', 5, 0, 1440) * 60;
    }

    /**
     * 给重置链接签名的密钥；没填时由站点密钥派生，这样不用配置也能签名
     * @return string
     */
    public function getPassportHmacKey()
    {
        $key = trim((string) $this->opt('passportHmacKey', ''));
        if ($key !== '') {
            return $key;
        }
        return hash('sha256', 'xcaptcha-passport|' . Helper::options()->secret);
    }

    /**
     * 是否按 IP 限制请求频率
     * @return boolean
     */
    public function isPassportRateLimitEnabled()
    {
        return (string) $this->opt('passportRateLimit', '1') === '1';
    }

    /**
     * 统计窗口内最多提交次数
     * @return int
     */
    public function getPassportRateLimitAttempts()
    {
        return $this->optInt('passportRateLimitAttempts', 20, 1, 1000);
    }

    /**
     * 超出后封禁时长
     * @return int 秒
     */
    public function getPassportRateLimitLock()
    {
        return $this->optInt('passportRateLimitLock', 5, 1, 1440) * 60;
    }

    /**
     * 新密码是否要求复杂度
     * @return boolean
     */
    public function isPassportPasswordComplexityEnabled()
    {
        return (string) $this->opt('passportPasswordComplexity', '1') === '1';
    }

    /**
     * 新密码最短长度
     * @return int
     */
    public function getPassportPasswordMinLength()
    {
        return $this->optInt('passportPasswordMinLength', 8, 1, 64);
    }
}

class XCaptcha_GeetestInfo
{
    public $dismod;

    public function __construct($dismod){
        $this->dismod = $dismod;
    }
}
