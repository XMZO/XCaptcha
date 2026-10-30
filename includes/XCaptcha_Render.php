<?php

include_once "XCaptcha_Utils.php";

class XCaptcha_Render
{
    /**
     * 输出通用部分HTML和JS
     * @static
     * @param string  $cdnUrl        验证码渲染脚本CDN地址
     * @param string  $captchaScript 插入的JS脚本
     * @param string  $parg          哪个页面
     * @return {*}
     */    
    private static function echoContent($cdnUrl, $captchaScript, $page, $captchaChoosen)
    {
        echo <<<EOF
        <style rel="stylesheet">
        .precheck-label { line-height: 44px;text-align:center;background-color: rgba(128,128,128,.12); color: inherit; }
        </style>
        <script>
            window.beforeCheckCallback = () => {
                setTimeout(() => {
                    const checkLabel = document.getElementsByClassName('check-label');
                    if (checkLabel.length > 0) {
                        precheckLabel = document.getElementsByClassName('precheck-label');
                        for(var i=0; i<precheckLabel.length; i++){
                            precheckLabel[i].style.display = 'none';
                        }
                    }
                }, 50);
            }
        </script>
EOF;

        if($page == 'comments'){
            // 评论页面需要禁用 custom-submit-button
            echo <<<EOF
        <script>
            window.addEventListener('load', ()=>{
                const buttons = document.querySelectorAll('.custom-submit-button');
                buttons.forEach(btn => {
                    btn.disabled = true;
                });
            });
        </script>
EOF;
        }

        if($page == 'login'){
            echo <<<EOF
		    <script>
                var captchaVerified = false;
                var buttonObserver = null;

			    window.checkCallback = () => {
                    if (captchaVerified) return;  // 防止重复执行
                    captchaVerified = true;

                    var jqForm = $("form");
                    var jqFormSubmit = jqForm.find(":submit");

                    // 启用按钮
                    jqFormSubmit.prop('disabled', false);
                    jqFormSubmit.removeAttr('disabled');
                    jqFormSubmit[0].disabled = false;

                    // 使用 MutationObserver 监控按钮状态，防止被其他代码禁用
                    if (jqFormSubmit[0] && !buttonObserver) {
                        buttonObserver = new MutationObserver(function(mutations) {
                            mutations.forEach(function(mutation) {
                                if (mutation.type === 'attributes' && mutation.attributeName === 'disabled') {
                                    if (captchaVerified && jqFormSubmit[0].disabled) {
                                        jqFormSubmit[0].disabled = false;
                                        jqFormSubmit.removeAttr('disabled');
                                    }
                                }
                            });
                        });

                        buttonObserver.observe(jqFormSubmit[0], {
                            attributes: true,
                            attributeFilter: ['disabled']
                        });

                        // 表单提交时清理 observer
                        jqForm.one('submit', function() {
                            if (buttonObserver) {
                                buttonObserver.disconnect();
                                buttonObserver = null;
                            }
                        });
                    }
                };

                $(document).ready(function () {
                    var jqForm = $("form");
                    var jqFormSubmit = jqForm.find(":submit");
                    jqFormSubmit.prop('disabled', true);
                    jqFormSubmit.parent().before(`$captchaScript`);
                    jqFormSubmit.parent().before(`<div class="precheck-label"><p class="waiting">行为验证™ 安全组件加载中...</p></div>`);
                });
		    </script>

EOF;
        }else if($page == 'comments'){
            echo <<<EOF
            <script>
            window.checkCallback = ()=>{
                var btns = document.getElementsByClassName('custom-submit-button');
                for(var i=0; i<btns.length; i++){
                    btns[i].disabled = false;
                }
            }
            </script>
EOF;
            echo $captchaScript;
            echo '<div class="precheck-label">行为验证™ 安全组件加载中...</div>';

        }   
        echo<<<EOF
        <script src="$cdnUrl"></script>
EOF;
    }

    /**
     * 输出Geetest渲染的HTML JS
     * @param string $widgetSize 尺寸
     * @param XCaptcha_GeetestInfo $geetest 极验证信息类
     */    
    private static function echoGeetestContent($widgetSize, XCaptcha_GeetestInfo $geetest)
    {
        $sizeSelector = ["normal" => "300px", "flexible"=>"100%", "compact" => "150px"];
        $geetestSize = $sizeSelector[$widgetSize];
        $dismod = $geetest->dismod;

        $ajaxUri = '/index.php/action/xcaptcha?do=ajaxResponseCaptchaData&type=geetest';
        echo <<<EOF
        <script>
            console.log("XCaptcha: 正在配置Geetest初始化函数...");
            const initializeGeetestCaptcha = ()=>{
                var ajaxUri = "{$ajaxUri}";
                var url = ajaxUri + '&t=' + (new Date()).getTime();
                
                fetch(url, {method: 'GET'})
               .then(function (response) {
                    if (!response.ok){
                        throw new Error(`Http error!`);
                    }
                    return response.text();
                })
               .then(function (text) {
                    try{
                        const data = JSON.parse(text);
                        initGeetest({
                            gt: data.gt,
                            challenge: data.challenge,
                            new_captcha: data.new_captcha,
                            product: '$dismod',
                            offline:!data.success,
                            width: '$geetestSize'
                        }, function (captchaObj) {
                            console.log("XCaptcha: Geetest请求成功!");
                            var jqGtCaptcha = document.getElementById('gt-captcha');
                            captchaObj.appendTo(jqGtCaptcha);
                            captchaObj.onSuccess(window.checkCallback);
                            window.beforeCheckCallback && window.beforeCheckCallback();
                        });
                    }catch(e){
                        console.error("XCaptcha: JSON解析错误: ", e);
                        console.error("XCaptcha: 导致解析错误的文本: ", text);
                    }
                    
                })
               .catch(function (error) {
                    console.log('请求出错：', error);
                });
            }
            initializeGeetestCaptcha();
            
		</script>

EOF;

    }

    /** 以 Web Component 形式渲染、由 XCaptchaWidgets 统一绑定的验证工具 */
    private static $widgetTypes = ['altcha', 'cap'];

    private static function isWidgetType($captchaChoosen)
    {
        return in_array($captchaChoosen, self::$widgetTypes, true);
    }

    /**
     * 生成页面内唯一的组件 id。
     * 主题（如 handsome）会在一个页面里同时放评论、登录、注册三个组件，
     * PJAX 换进来的片段又来自另一次请求，所以用随机 id 而不是计数器。
     */
    private static function newWidgetId($captchaChoosen)
    {
        return 'xcaptcha-' . $captchaChoosen . '-' . bin2hex(random_bytes(4));
    }

    /**
     * 生成组件 HTML
     * @param XCaptcha_Config $config 配置类
     * @param string          $id     组件 id
     * @return Array<string>          [组件HTML, 组件脚本地址]
     */
    private static function widgetHtml(XCaptcha_Config $config, $id)
    {
        if ($config->getCaptchaChoosen() == 'cap') {
            $cdnUrl = $config->getCdnUrl();
            if ($cdnUrl == '') $cdnUrl = $config->getCapEndpoint() . '/assets/widget.js';
            // required：未验证时浏览器自己拦住表单提交（登录/注册按钮不禁用，靠这个）
            $html = '<cap-widget id="' . htmlspecialchars($id, ENT_QUOTES) . '" class="check-label"'
                . ' data-cap-api-endpoint="' . htmlspecialchars($config->getCapApiEndpoint(), ENT_QUOTES) . '" required></cap-widget>';
            return [$html, $cdnUrl];
        }

        return XCaptcha_Utils::getCaptchaScript(
            'altcha',
            $config->getCdnUrl(),
            $config->getWidgetColor(),
            $config->getwidgetSize(),
            $config->getCaptchaId(),
            $id
        );
    }

    /**
     * 输出组件的样式、组件脚本和绑定函数（重复输出无害：脚本只加载一次，绑定函数只定义一次）
     * @param XCaptcha_Config $config 配置类
     * @param string          $cdnUrl 组件脚本地址
     */
    private static function echoWidgetRuntime(XCaptcha_Config $config, $cdnUrl)
    {
        // 颜色跟随页面文字色，深色模式下不再是一块白底
        echo <<<EOF
        <style>
        .precheck-label { line-height: 44px;text-align:center;background-color: rgba(128,128,128,.12); color: inherit; }
        altcha-widget { --altcha-color-base: transparent; --altcha-color-text: currentColor; --altcha-color-border: rgba(128,128,128,.35); --altcha-color-footer-bg: transparent; }
        cap-widget { --cap-background: transparent; --cap-color: currentColor; --cap-border-color: rgba(128,128,128,.35); --cap-checkbox-background: transparent; --cap-checkbox-border: 1px solid rgba(128,128,128,.6); --cap-spinner-color: currentColor; --cap-spinner-background-color: rgba(128,128,128,.25); }
        </style>
EOF;
        if ($config->getCaptchaChoosen() == 'cap') {
            // 组件默认从 jsDelivr 拉 WASM，国内常常打不开；用 Cap 服务自带的资源时一并从服务拿
            $assets = $config->getCdnUrl() == '' ? $config->getCapEndpoint() . '/assets/' : '';
            $flags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES;
            echo '<script>(function (src, assets) {'
                . 'if (assets) {'
                . 'window.CAP_CUSTOM_WASM_URL = window.CAP_CUSTOM_WASM_URL || assets + "cap_wasm_bg.wasm";'
                . 'window.CAP_CUSTOM_HASHWX_URL = window.CAP_CUSTOM_HASHWX_URL || assets + "hashwx.wasm";'
                . '}'
                // 组件上的 Cap 链接点开时默认会带上当前页面地址
                . 'window.CAP_DISABLE_WIDGET_REF = true;'
                // 同一页面有多个组件，PJAX 还会再输出一次，脚本只插一次
                . 'if (document.querySelector("script[data-xcaptcha-cap]")) return;'
                . 'var s = document.createElement("script");'
                . 's.src = src; s.async = true; s.setAttribute("data-xcaptcha-cap", "");'
                . 'document.head.appendChild(s);'
                . '})(' . json_encode($cdnUrl, $flags) . ', ' . json_encode($assets, $flags) . ');</script>';
        } else {
            echo '<script src="' . htmlspecialchars($cdnUrl, ENT_QUOTES) . '" type="module"></script>';
        }
        echo <<<'EOF'
        <script>
        window.XCaptchaWidgets = window.XCaptchaWidgets || (function () {
            function absolute(url) {
                try { return new URL(url, location.href).href; } catch (e) { return String(url); }
            }

            // 验证状态变化：Altcha 发 statechange，Cap 发 solve / reset / error
            function watchState(widget, onChange) {
                if (widget.tagName.toLowerCase() === 'cap-widget') {
                    widget.addEventListener('solve', function () { onChange(true); });
                    widget.addEventListener('reset', function () { onChange(false); });
                    widget.addEventListener('error', function () { onChange(false); });
                    return;
                }
                widget.addEventListener('statechange', function (ev) {
                    onChange(!!ev.detail && ev.detail.state === 'verified');
                });
            }

            // 只管自己所在的表单：gate 是该表单里「验证通过前禁用」的按钮选择器，留空则不禁用
            function bind(id, gate) {
                var widget = document.getElementById(id);
                if (!widget || widget.getAttribute('data-xcaptcha-bound')) return;
                widget.setAttribute('data-xcaptcha-bound', '1');
                var form = widget.closest('form');

                var verified = false;
                function setGate(open) {
                    if (!form || !gate) return;
                    var buttons = form.querySelectorAll(gate);
                    for (var i = 0; i < buttons.length; i++) buttons[i].disabled = !open;
                }
                // 渲染时立即禁用，PJAX 换入的片段此时按钮已在；整页加载时按钮可能还在后面没解析到，解析完再补一次
                setGate(false);
                if (document.readyState === 'loading') {
                    document.addEventListener('DOMContentLoaded', function () { setGate(verified); });
                }
                watchState(widget, function (ok) {
                    verified = ok;
                    setGate(verified);
                });

                if (window.customElements) {
                    customElements.whenDefined(widget.tagName.toLowerCase()).then(function () {
                        var label = document.getElementById(id + '-precheck');
                        if (label) label.style.display = 'none';
                    });
                }

                // 一次验证只能用一次：这个表单的 AJAX 提交（handsome 的评论、登录）回来后重新验证
                function watchAjax() {
                    if (!window.jQuery) return false;
                    jQuery(document).ajaxComplete(function (event, xhr, settings) {
                        if (!widget.isConnected || typeof widget.reset !== 'function') return;
                        var action = form.getAttribute('action');
                        if (action && absolute(settings.url) === absolute(action)) widget.reset();
                    });
                    return true;
                }
                // 主题可能把 jQuery 放在页面底部，那就等解析完再挂
                if (form && !watchAjax() && document.readyState === 'loading') {
                    document.addEventListener('DOMContentLoaded', watchAjax);
                }
            }

            return { bind: bind };
        })();
        </script>
EOF;
    }

    /**
     * 在表单里原地输出一个组件并绑定
     * @param XCaptcha_Config $config 配置类
     * @param string          $gate   验证通过前禁用的按钮选择器，留空不禁用
     */
    private static function echoWidget(XCaptcha_Config $config, $gate)
    {
        $id = self::newWidgetId($config->getCaptchaChoosen());
        list($widgetHtml, $cdnUrl) = self::widgetHtml($config, $id);

        self::echoWidgetRuntime($config, $cdnUrl);
        echo $widgetHtml;
        echo '<div class="precheck-label" id="' . $id . '-precheck">行为验证™ 安全组件加载中...</div>';
        echo '<script>XCaptchaWidgets.bind(' . json_encode($id) . ', ' . json_encode($gate) . ');</script>';
    }

    /**
     * 后台登录/注册页：表单不在插件手里，用 jQuery 插到提交按钮前面再绑定
     * @param XCaptcha_Config $config 配置类
     */
    private static function echoLoginPageWidget(XCaptcha_Config $config)
    {
        $id = self::newWidgetId($config->getCaptchaChoosen());
        list($widgetHtml, $cdnUrl) = self::widgetHtml($config, $id);
        $html = $widgetHtml . '<div class="precheck-label" id="' . $id . '-precheck">行为验证™ 安全组件加载中...</div>';
        $flags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE;

        self::echoWidgetRuntime($config, $cdnUrl);
        echo '<script>$(function () {'
            . 'var submit = $("form").first().find(":submit").first();'
            . 'submit.parent().before(' . json_encode($html, $flags) . ');'
            . 'XCaptchaWidgets.bind(' . json_encode($id) . ', "button[type=submit], input[type=submit]");'
            . '});</script>';
    }

    /**
     * 渲染登录/注册页的验证码
     * @static
     * @param XCaptcha_Config $config 配置类
     */    
    public static function renderLoginPage(XCaptcha_Config $config)
    {
        $widgetOptions = Typecho_Widget::widget('Widget_Options');
        if (stripos($widgetOptions->request->getRequestUrl(), 'login.php') === false &&
			stripos($widgetOptions->request->getRequestUrl(), 'register.php') === false) {
            return;
        }

        // 是否启用login的验证码
        if(!$config->isCaptchaEnabledOnPage('login')){
            return;
        }
        // 密钥是否为空，如果为空则不渲染验证码
        if(!$config->checkKeys()){
            Typecho_Widget::widget('Widget_Notice')->set(_t('XCaptcha: No keys'), 'error');
			return;
        }

        if(self::isWidgetType($config->getCaptchaChoosen())){
            self::echoLoginPageWidget($config);
            return;
        }

        list($captchaScript, $cdnUrl) = XCaptcha_Utils::getCaptchaScript(
            $config->getCaptchaChoosen(),
            $config->getCdnUrl(),
            $config->getWidgetColor(),
            $config->getwidgetSize(),
            $config->getCaptchaId()
        );
        if (!$captchaScript) return;    // 模板不存在则不渲染

        self::echoContent($cdnUrl, $captchaScript, 'login', $config->getCaptchaChoosen());    // 输出通用部分
        if($config->getCaptchaChoosen() == 'geetest'){
            // geetest需要额外配置
            self::echoGeetestContent($config->getWidgetSize(), $config->getGeetestConfig());
        }
    }

    /**
     * 渲染评论区的验证码
     * @static
     * @param XCaptcha_Config $config 配置类
     */  
    public static function renderCommentPage(XCaptcha_Config $config)
    {
		// 是否启用comments的验证码
        if(!$config->isCaptchaEnabledOnPage('comments')){
            return;
        }
        // 密钥是否为空，如果为空则不渲染验证码
        if(!$config->checkKeys()){
            Typecho_Widget::widget('Widget_Notice')->set(_t('XCaptcha: No keys'), 'error');
			return;
        }
        // 是否开启登录用户不校验 且 用户处于登录状态 且 为administrator，都符合则不渲染
        $user = Typecho_Widget::widget('Widget_User');
        if($config->isAuthorUncheck() && $user->hasLogin() && $user->pass('administrator', true))
            return;

        if(self::isWidgetType($config->getCaptchaChoosen())){
            // 主题需给评论提交按钮加 custom-submit-button，验证通过前禁用
            self::echoWidget($config, '.custom-submit-button');
            return;
        }

        list($captchaScript, $cdnUrl) = XCaptcha_Utils::getCaptchaScript(
            $config->getCaptchaChoosen(),
            $config->getCdnUrl(),
            $config->getWidgetColor(),
            $config->getwidgetSize(),
            $config->getCaptchaId()
        );
        if (!$captchaScript) return;

        self::echoContent($cdnUrl, $captchaScript, 'comments', $config->getCaptchaChoosen());    // 输出通用部分
        if($config->getCaptchaChoosen() == 'geetest'){
            // geetest需要额外配置
            self::echoGeetestContent($config->getWidgetSize(), $config->getGeetestConfig());
        }
    }

    /**
     * 渲染前端登录/注册表单的验证码（用于主题中的登录表单）
     * @static
     * @param XCaptcha_Config $config 配置类
     */
    public static function renderFrontendLoginForm(XCaptcha_Config $config)
    {
        // 是否启用login的验证码
        if(!$config->isCaptchaEnabledOnPage('login')){
            return;
        }
        // 密钥是否为空，如果为空则不渲染验证码
        if(!$config->checkKeys()){
            return;
        }

        if(self::isWidgetType($config->getCaptchaChoosen())){
            // 登录/注册按钮不禁用，组件自带的必填校验会拦住未验证的提交
            self::echoWidget($config, '');
            return;
        }

        list($captchaScript, $cdnUrl) = XCaptcha_Utils::getCaptchaScript(
            $config->getCaptchaChoosen(),
            $config->getCdnUrl(),
            $config->getWidgetColor(),
            $config->getwidgetSize(),
            $config->getCaptchaId()
        );
        if (!$captchaScript) return;

        // 输出验证码 HTML 和回调函数
        echo <<<EOF
        <script>
            window.beforeCheckCallback = () => {
                setTimeout(() => {
                    const checkLabel = document.getElementsByClassName('check-label');
                    if (checkLabel.length > 0) {
                        const precheckLabel = document.getElementsByClassName('precheck-label');
                        for(var i=0; i<precheckLabel.length; i++){
                            precheckLabel[i].style.display = 'none';
                        }
                    }
                }, 50);
            }
        </script>
EOF;
        echo $captchaScript;
        echo '<div class="precheck-label">行为验证™ 安全组件加载中...</div>';

        // 加载验证码 CDN
        $captchaChoosen = $config->getCaptchaChoosen();
        echo '<script src="'.$cdnUrl.'"></script>';

        // Geetest 需要额外配置
        if($captchaChoosen == 'geetest'){
            self::echoGeetestContent($config->getWidgetSize(), $config->getGeetestConfig());
        }
    }

}