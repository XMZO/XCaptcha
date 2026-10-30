# XCaptcha-for-Typecho

用于Typecho博客系统的插件，为你的博客评论、注册、登陆添加验证码，并提供带验证码的找回密码功能。


## 支持

目前支持的验证码如下：

* [hCaptcha](https://www.hcaptcha.com)
* [Cloudflare Turnstile](https://www.cloudflare.com)
* [Google reCaptcha v2](https://developers.google.cn/recaptcha/docs/display?hl=zh-cn)
* [极验证Geetest v3](https://www2.geetest.com)
* [altcha-org](https://altcha.org/docs/get-started/)
* [Cap](https://capjs.js.org)（需自行部署 Cap Standalone）

考虑接下来支持：
* Google reCaptcha v3
* 极验证Geetest v4


## 使用方式

### 下载本项目

可以通过Git获取本项目：

```sh
git clone https://github.com/CairBin/XCaptcha.git
```

或者下载本项目的压缩包

```sh
wget https://github.com/CairBin/XCaptcha/archive/refs/heads/main.zip
```


以上两种方式通常包含插件最近提交的代码，包括正在开发的功能和补丁，但也有可能不稳定，建议去[Release页面](https://github.com/CairBin/XCaptcha/releases/latest)下载Release版本。


解压后需要将插件文件夹的名称改为`XCaptcha`。


### 配置插件

将`XCaptcha`文件夹上传到你的Typecho博客的`usr/plugins/`目录下，在Typecho后台的插件面板里激活即可。

从上述所支持验证码的官网注册账户，然后获取你站点的`Captcha ID`和`Secret Key`，有的也叫`Site Key`和`Secret Key`，本质上都一样，对应公钥和私钥。前者用于前端标识可以公开，后者用于服务端向验证码服务器校验需要保密。

修改主题模板的`comments.php`文件，主题目录是`usr/themes/your_theme/`，在评论提交按钮之前或者表单最后添加一行代码，然后回到博客后台配置插件，将获取到的ID/Key填写进去，以及配置其他参数即可。

```php
<?php if (array_key_exists('XCaptcha', Typecho_Plugin::export()['activated'])) : XCaptcha_Plugin::showCaptcha(); endif; ?>
```

如果你想要禁用评论提交按钮，经过验证后才可点击提交表单，只需要给按钮添加类`custom-submit-button`即可，以默认主题为例：

```php
<button type="submit" class="custom-submit-button submit"><?php _e('提交评论'); ?></button>
```

### Altcha配置

Altcha采用本地验证方案，自动生成HMAC密钥，无需手动填写`Captcha ID`和`Secret Key`

### Cap配置

Cap 需要一个自己部署的 [Cap Standalone](https://capjs.js.org/guide/standalone/) 服务，可以和博客不在同一台服务器上：

1. 在 Cap 后台创建站点 key，记下站点 key 和它的密钥（Secret Key，不是登录后台用的 `ADMIN_KEY`）
2. 服务端设置 `ENABLE_ASSETS_SERVER=true`，组件脚本和 WASM 从服务自己的 `/assets/` 加载，不经过 jsDelivr；建议同时用 `WIDGET_VERSION`、`WASM_VERSION` 固定版本
3. 插件里「验证工具」选 Cap，`Captcha ID` 填站点 key，`Secret Key` 填密钥，「Cap 服务地址」填服务的根地址，如 `https://cap.example.com`

访客的浏览器会直接向 Cap 服务请求题目、提交答案，博客服务器再向 `服务地址/站点key/siteverify` 核对 token，所以 Cap 服务要让访客和博客服务器都能访问到。如需限制来源，在 Cap 里把站点 key 的 CORS 设成博客域名。


部分主题可能不存在`comments.php`文件，这时候你需要从其他文件里找到评论表单，并在合适的位置添加这行代码。

## 找回密码

v1.4.0 起内置找回密码（由 [PLUGION-Passport](https://github.com/XMZO/PLUGION-Passport) 合并而来）：访客在 `/passport/forgot` 填用户名或邮箱，收到邮件后在 `/passport/reset` 设置新密码。两个页面套用后台登录页的样式，并始终带验证码。

1. 在插件配置里把「启用找回密码」设为开启，填好 SMTP（服务器、端口、加密方式、帐号、密码），保存。保存时会自动建表并注册这两个地址，提示里会给出找回密码页的链接；关闭后保存则移除地址
2. 「找回密码使用的验证码」默认跟随上方评论、登录用的验证工具和密钥；也可以单独配置一套（比如评论用 Altcha、找回密码用 Cap），或者不用验证码
3. 开启后后台登录页「返回首页」旁边会多出「忘记密码」链接，可以关掉
4. 以前用 Passport 插件的，先在插件列表里禁用它再开启这里的找回密码；它的数据表会保留并直接沿用，SMTP 等设置需要在这里重新填写一次

安全相关的默认行为：

* 重置链接 60 分钟有效、只能用一次，链接里的令牌在数据库中只存哈希，并带 HMAC 签名（密钥留空时由 Typecho 站点密钥派生）
* 同一账号两次发信最少间隔 5 分钟，间隔内再申请不发邮件也不提示；账号不存在时提示与成功时相同
* 按 IP 限制提交频率，10 分钟内超过 20 次封禁 5 分钟，配置页可以查看记录和解封
* 新密码默认至少 8 位并包含大小写字母、数字和特殊字符，不能与用户名或邮箱相同
* 重置成功后该用户所有已登录的会话一起失效

邮件标题、正文模板、有效期、发信间隔、频率阈值、密码规则都可以在配置里改。页面地址固定为 `/passport/forgot` 与 `/passport/reset`，如果前面有 CDN，记得不要缓存 `/passport/` 下的页面。

## 插件导致无法登陆后台

登陆页面启用验证码，如果配置不当，会导致验证一直失败而无法进入博客后台。

如果遇到这种问题，请修改Typecho的数据库的`typecho_options`表中，`name`属性为`plugins`的那一行的值为`a:0:{}`以禁用所有插件。


**非常重要：在进行此操作前请务必备份数据库！**


## URL参数填写说明


* “验证码JS地址”取决于网络情况，它会替代原本引入的验证初始化脚本（不是二次验证的接口），遇到某些JS加载缓慢时使用，**如果不明白留空即可**。“校验地址”用于更换服务端验证接口，留空使用默认地址，一般在默认接口无法访问或者失效时填写。

* 区分JQuery CDN URL与上述部分，JQuery CDN URL用于通过CDN引入JQuery（不能留空），默认使用jsdelivr，而上述部分是用于**初始化/校验**验证码。

* 如果勾选了开启登陆页面验证码则，注册页面也会跟着开启，前提是你启动了注册功能。


## PJAX回调问题

据反馈部分主题开启PJAX无刷新后可能会导致验证码加载不正常，请根据主题自行设置PJAX回调函数。Handsome主题经过测试可以正常使用，其他主题若存在此问题请在issue反馈，但是部分作者没使用过的付费主题可能难以适配。

## PHP8兼容性问题

于v1.3.0正式版兼容PHP8，并在PHP82环境下通过测试。详细请参看“重要版本说明”部分和[CHANGELOG.md](./CHANGELOG.md)。

## PHP72兼容性问题

于v1.3.1版本优化了Altcha PHP72的兼容性，PHP72没有`JSON_THROW_ON_ERROR`(PHP73引入)，会导致`./lib/class.altcha.php`报错

## 重要版本说明

### 关于v1.1.0及后续版本

* XCaptcha v1.1.0及后续版本，极验证加载不再依赖JQuery，启用JQuery与JQuery CDN URL项已经被移除。
* 自此版本起兼容旧版本的Typecho 1.1

### 关于v1.2.1版本及后续版本

通过移除./includes/XCaptcha_Config.php的personalConfig方法，修复Typecho控制台 -> 个人配置 显示XCaptcha配置找不到的bug(issue #1 )


### 关于v1.3.0及后续版本

* v1.3.0版本添加了渲染组件成功以及校验成功的回调函数，用于**禁用/启用按钮**以及提示**验证码组件正在加载**，因此对于`hcaptcha`、`cloudflare`以及`recaptcha`在配置`cdnUrl`的时候需要设置`onload`参数为`beforeCheckCallback`，例如`https://hcaptcha.com/1/api.js?onload=beforeCheckCallback`（更具体的参数配置并不属于本文范畴，你应当参考所使用验证码的官方文档）

* v1.3.0本分为预发布测试版和正式版，预发布版本存在altcha评论错误以及PHP8下极验证无法渲染问题，在v1.3.0正式版已经修复，Release页面的预发布版本已经删除，现在可下载的都是正式版本，建议及时更新。

### 关于v1.3.1及其后续版本

* 自v1.3.1版本，优化了Altcha PHP72的兼容性


## 反馈

反馈bug请提交issue仓库，请务必声明Typecho版本号、XCaptcha版本号、使用主题、Apache版本/Nginx版本等信息，方便问题复现。此外最好备注站点信息，方便作者查看问题。


## 项目展示

<img src="./img/login_page.png" alt="登录界面" width="240px"/>

<img src="./img/plugin_config.png" alt="插件配置" width="240px"/>

<img src="./img/comment_area.png" alt="评论区" width="240px"/>


## 特别感谢

本项目在编写时参考的插件项目：

* [noisky/typecho-plugin-geetest](https://github.com/noisky/typecho-plugin-geetest)
* [scenery/typecho-plugins/CaptchPlus](https://github.com/scenery/typecho-plugins)
