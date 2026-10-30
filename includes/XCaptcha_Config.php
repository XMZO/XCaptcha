<?php

/**
 * 获取插件配置
 * @author CairBin(Xinyi Liu)
 */
class XCaptcha_Config{
    protected $options;
    protected $geetest;
    
    public function __construct()
    {
        $this->options = Helper::options()->plugin('XCaptcha');
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
        $form->addInput(new Typecho_Widget_Helper_Form_Element_Checkbox('pages', ["comments" => "评论", "login" => "登陆页"], [], _t('应用到'), _t('在哪些页面应用验证码')));
        $form->addInput(new Typecho_Widget_Helper_Form_Element_Select('isAuthorUncheck', array(false => '关闭', true => '开启'), false, _t('管理员评论无需验证')));
        $form->addInput(new Typecho_Widget_Helper_Form_Element_Text('captchaId', NULL, '', _t('Captcha ID'), _t('公钥(ID)')));
        $form->addInput(new Typecho_Widget_Helper_Form_Element_Text('secretKey', NULL, '', _t('Secret Key'), _t('私钥(Key)')));
        $form->addInput(new Typecho_Widget_Helper_Form_Element_Radio('widgetColor', ["auto"=>"自动", "light" => "浅色", "dark" => "深色"], "auto", _t('颜色'), _t('设置验证工具主题颜色，默认为浅色<br/>- hCaptcha不支持自动<br/>- reCaptcha v2不支持自动<br/>- 极验证v3不支持颜色<br/>- altcha、Cap不支持配置，跟随页面文字颜色')));
        $form->addInput(new Typecho_Widget_Helper_Form_Element_Radio('widgetSize', ["normal" => "常规", "flexible"=>"灵活", "compact" => "紧凑"], "normal", _t('样式'), _t('设置验证框布局样式，默认为常规<br/>- hCaptcha不支持灵活<br/>- reCaptcha v2不支持灵活<br/>- altcha、Cap不支持配置')));
        $form->addInput(new Typecho_Widget_Helper_Form_Element_Radio('captchaChoosen', [ "altcha" => "Altcha", "cap" => "Cap", "hcaptcha" => "hCaptcha", "cloudflare" => "Cloudflare", "recaptcha" => "Google reCaptcha v2", "geetest" => "极验证 v3"], "hcaptcha", _t('验证工具'), _t('选择验证工具<br/>- altcha为纯本地方案，但是需要页面支持HTTPS才能使用<br/>- Cap需要自己部署的 Cap Standalone 服务，见下方「Cap 服务地址」')));
        $form->addInput(new Typecho_Widget_Helper_Form_Element_Text('cdnUrl', NULL, '', _t('验证码JS地址:'), _t('用于CDN加速加载验证码, 留空引入默认JS</br>注意使用 https 协议')));
        $form->addInput(new Typecho_Widget_Helper_Form_Element_Text('verifyUrl', NULL, '', _t('校验地址:'), _t('用于设置验证码校验接口, 留空使用默认, 此项不支持极验证')));
        $form->addInput(new Typecho_Widget_Helper_Form_Element_Text('capEndpoint', NULL, '', _t('Cap 服务地址:'), _t('仅 Cap 使用：Cap Standalone 的地址，如 https://cap.example.com，不含站点 key<br/>- Captcha ID 填站点 key，Secret Key 填该站点 key 的密钥（不是后台登录用的 ADMIN_KEY）<br/>- 验证码JS地址留空时，组件脚本和 WASM 都从该服务的 /assets/ 加载，需在服务端设置 ENABLE_ASSETS_SERVER=true<br/>- 校验地址留空时使用 服务地址/站点key/siteverify')));
        $form->addInput(new Typecho_Widget_Helper_Form_Element_Select('dismod', array('float' => '浮动式（float）', 'embed' => '嵌入式（embed）', 'popup' => '弹出框（popup）'), 'float', _t('极验证展现形式：')));
    }

    /**
     * 获取启用的页面
     * @return Array<string> 包含启用页面名称的数组
     */    
    public function getPages()
    {
        return $this->options->pages;
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
        return $this->options->captchaId;
    }

    /**
     * 获取验证码私钥
     * @return string 验证码私钥
     */
    public function getSecretKey()
    {
        return $this->options->secretKey;
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
        if ($this->getCaptchaChoosen() === "altcha") {
            return true; // 如果 getCaptchaChoosen 是 "altcha"，直接返回 true
        }

        $captchaId = $this->getCaptchaId();
        $secretKey = $this->getSecretKey();
        if ($this->getCaptchaChoosen() === "cap" && $this->getCapEndpoint() === '') {
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
        return $this->options->captchaChoosen;
    }

    /**
     * 获取验证码加载脚本的CDN路径
     * @return string 路径
     */
    public function getCdnUrl()
    {
        return $this->options->cdnUrl;
    }

    /**
     * 获取服务端校验接口
     * @return string 路径
     */
    public function getVerifyUrl()
    {
        return $this->options->verifyUrl;
    }

    /**
     * 获取 Cap Standalone 服务地址（去掉末尾的 /）
     * @return string 服务地址，未填写时为空字符串
     */
    public function getCapEndpoint()
    {
        return rtrim(trim((string) $this->options->capEndpoint), '/');
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
}

class XCaptcha_GeetestInfo
{
    public $dismod;

    public function __construct($dismod){
        $this->dismod = $dismod;
    }
}