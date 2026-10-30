<?php
if (!defined('__TYPECHO_ROOT_DIR__')) exit;

/**
 * XCaptcha 设置页：后台「设置 → XCaptcha」，由 admin/extending.php 引入。
 * 可用变量来自后台的 common.php：$options、$user、$security、$menu、$request。
 * 保存和各种检测都发到 /action/xcaptcha-admin（Admin.php）。
 */
$user->pass('administrator');
class_exists('XCaptcha_Plugin');

$adminDir = rtrim(__TYPECHO_ROOT_DIR__ . __TYPECHO_ADMIN_DIR__, '/') . '/';
$s = XCaptcha_Settings::current();
$secretSet = [];
foreach (XCaptcha_Settings::fields() as $name => $spec) {
    if ($spec['type'] === 'secret') {
        $secretSet[$name] = (string) $s[$name] !== '';
    }
}
$e = function ($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
};
$checked = function ($on) {
    return $on ? ' checked' : '';
};

$token = $security->getToken(XCaptcha_Settings::TOKEN_SUFFIX);
$actionUrl = Typecho_Common::url('/action/' . XCaptcha_Settings::ADMIN_ACTION, $options->index);
$staticUrl = Typecho_Common::url('XCaptcha/static/', $options->pluginUrl);
$assetVersion = function ($file) {
    $path = __DIR__ . '/static/' . $file;
    return is_file($path) ? substr(md5_file($path), 0, 8) : '0';
};
$forgotUrl = Typecho_Common::url(XCaptcha_Passport::PATH_FORGOT, $options->index);

$tools = [
    'altcha' => ['Altcha', _t('本地出题，无需密钥')],
    'cap' => ['Cap', _t('自建服务，含环境检测')],
    'hcaptcha' => ['hCaptcha', _t('可能有图片题')],
    'cloudflare' => ['Turnstile', 'Cloudflare'],
    'recaptcha' => ['reCAPTCHA v2', _t('经 recaptcha.net')],
    'geetest' => [_t('极验 v3'), _t('服务端先取题')],
];
$pages = is_array($s['pages']) ? $s['pages'] : [];
$passportOn = $s['passportEnabled'] === '1';

$fails = XCaptcha_Passport::recentFails(25);
$now = time();
$lockedCount = 0;
foreach ($fails as $fail) {
    if ((int) $fail['locked_until'] > $now) {
        $lockedCount++;
    }
}
$logs = XCaptcha_Passport::recentLogs(30);
$events = [
    XCaptcha_Passport::EVENT_SENT => [_t('已发送重置邮件'), 'ok'],
    XCaptcha_Passport::EVENT_COOLDOWN => [_t('冷却中，未重复发信'), 'warn'],
    XCaptcha_Passport::EVENT_NOMATCH => [_t('没有匹配的账号'), 'muted'],
    XCaptcha_Passport::EVENT_MAIL_FAILED => [_t('邮件发送失败'), 'danger'],
    XCaptcha_Passport::EVENT_CAPTCHA_FAILED => [_t('验证码未通过'), 'muted'],
    XCaptcha_Passport::EVENT_RATE_LOCKED => [_t('提交太频繁，已封禁'), 'danger'],
    XCaptcha_Passport::EVENT_RESET => [_t('密码已重置'), 'accent'],
];
$formatTime = function ($timestamp) {
    return (new Typecho_Date((int) $timestamp))->format('m-d H:i');
};

$appliedTo = [];
if (in_array('comments', $pages, true)) $appliedTo[] = _t('评论');
if (in_array('login', $pages, true)) $appliedTo[] = _t('登录和注册');
$mainConfig = new XCaptcha_Config();

// 字段的错误提示占位，保存失败时由脚本填进去
$errorSlot = function ($name) use ($e) {
    return '<p class="xc-error" data-error-for="' . $e($name) . '" hidden></p>';
};
$textField = function ($name, $label, array $opt = []) use ($s, $e, $errorSlot) {
    $type = isset($opt['type']) ? $opt['type'] : 'text';
    $html = '<div class="xc-field' . (!empty($opt['wide']) ? ' xc-wide' : '') . '"' . (isset($opt['attrs']) ? ' ' . $opt['attrs'] : '') . '>'
        . '<label for="xc-' . $e($name) . '"' . (isset($opt['labelId']) ? ' id="' . $e($opt['labelId']) . '"' : '') . '>' . $label . '</label>'
        . '<input id="xc-' . $e($name) . '" name="' . $e($name) . '" type="' . $e($type) . '" value="' . $e($s[$name]) . '"'
        . (isset($opt['placeholder']) ? ' placeholder="' . $e($opt['placeholder']) . '"' : '')
        . (isset($opt['inputAttrs']) ? ' ' . $opt['inputAttrs'] : '')
        . ' autocomplete="off" spellcheck="false">';
    if (isset($opt['hint'])) {
        $html .= '<p class="xc-hint">' . $opt['hint'] . '</p>';
    }
    return $html . $errorSlot($name) . '</div>';
};
$secretField = function ($name, $label, array $opt = []) use ($secretSet, $e, $errorSlot) {
    $placeholder = $secretSet[$name] ? _t('已保存，留空不修改') : (isset($opt['empty']) ? $opt['empty'] : _t('未设置'));
    $html = '<div class="xc-field' . (!empty($opt['wide']) ? ' xc-wide' : '') . '"' . (isset($opt['attrs']) ? ' ' . $opt['attrs'] : '') . '>'
        . '<label for="xc-' . $e($name) . '">' . $label . '</label>'
        . '<input id="xc-' . $e($name) . '" name="' . $e($name) . '" type="password" value="" placeholder="' . $e($placeholder) . '"'
        . ' data-secret="' . $e($name) . '" autocomplete="new-password" spellcheck="false">';
    if (isset($opt['hint'])) {
        $html .= '<p class="xc-hint">' . $opt['hint'] . '</p>';
    }
    return $html . $errorSlot($name) . '</div>';
};
$numberInput = function ($name, $label) use ($s, $e) {
    $spec = XCaptcha_Settings::fields()[$name];
    return '<input class="xc-num" id="xc-' . $e($name) . '" name="' . $e($name) . '" type="number" inputmode="numeric"'
        . ' min="' . (int) $spec['min'] . '" max="' . (int) $spec['max'] . '" step="1" value="' . $e($s[$name]) . '" aria-label="' . $e($label) . '" required>';
};
$switch = function ($name, $labelledBy) use ($s, $e, $checked) {
    return '<span class="xc-switch"><input type="checkbox" role="switch" id="xc-' . $e($name) . '" name="' . $e($name) . '" value="1"'
        . $checked($s[$name] === '1') . ' aria-labelledby="' . $e($labelledBy) . '"><span class="xc-switch-track" aria-hidden="true"></span></span>';
};

include $adminDir . 'header.php';
include $adminDir . 'menu.php';
?>
<link rel="stylesheet" href="<?php echo $e($staticUrl . 'admin.css?v=' . $assetVersion('admin.css')); ?>">
<main class="main">
<div class="xc" id="xc" data-action="<?php echo $e($actionUrl); ?>" data-token="<?php echo $e($token); ?>">
    <header class="xc-head">
        <div class="xc-title">
            <h1><?php _e('XCaptcha'); ?></h1>
            <span class="xc-badge">v<?php echo $e(XCaptcha_Settings::VERSION); ?></span>
        </div>
        <div class="xc-status">
            <span class="xc-chip"><span class="xc-dot <?php echo $appliedTo && $mainConfig->checkKeys() ? 'is-ok' : 'is-warn'; ?>" aria-hidden="true"></span><?php
                echo $e(_t('验证码 · %s', $tools[$s['captchaChoosen']][0] ?? $s['captchaChoosen']));
                echo $e($appliedTo ? ' · ' . implode('、', $appliedTo) : ' · ' . _t('未应用到任何页面'));
            ?></span>
            <span class="xc-chip"><span class="xc-dot <?php echo $passportOn ? 'is-ok' : 'is-off'; ?>" aria-hidden="true"></span><?php echo $e(_t('找回密码 · %s', $passportOn ? _t('已开启') : _t('已关闭'))); ?></span>
            <?php if ($lockedCount > 0): ?>
            <span class="xc-chip is-danger"><?php echo $e(_t('封禁中 %d 个 IP', $lockedCount)); ?></span>
            <?php endif; ?>
        </div>
    </header>

    <div class="xc-layout">
        <nav class="xc-tabs" role="tablist" aria-label="<?php _e('设置分组'); ?>">
            <a role="tab" href="#captcha" id="xc-tab-captcha" aria-controls="xc-panel-captcha"><span class="xc-dot is-ok" aria-hidden="true"></span><?php _e('验证码'); ?></a>
            <a role="tab" href="#passport" id="xc-tab-passport" aria-controls="xc-panel-passport"><span class="xc-dot <?php echo $passportOn ? 'is-ok' : 'is-off'; ?>" aria-hidden="true"></span><?php _e('找回密码'); ?></a>
            <a role="tab" href="#mail" id="xc-tab-mail" aria-controls="xc-panel-mail"><span class="xc-dot <?php echo $s['passportSmtpHost'] !== '' ? 'is-ok' : 'is-off'; ?>" aria-hidden="true"></span><?php _e('邮件'); ?></a>
            <a role="tab" href="#security" id="xc-tab-security" aria-controls="xc-panel-security"><span class="xc-dot <?php echo $s['passportRateLimit'] === '1' ? 'is-ok' : 'is-warn'; ?>" aria-hidden="true"></span><?php _e('安全'); ?></a>
            <a role="tab" href="#logs" id="xc-tab-logs" aria-controls="xc-panel-logs"><span class="xc-dot <?php echo $lockedCount > 0 ? 'is-danger' : 'is-off'; ?>" aria-hidden="true"></span><?php _e('记录'); ?></a>
        </nav>

        <div class="xc-panels">
        <form id="xc-form" method="post" action="<?php echo $e(Typecho_Common::url('/action/' . XCaptcha_Settings::ADMIN_ACTION . '?do=save', $options->index)); ?>" novalidate>
            <input type="hidden" name="_" value="<?php echo $e($token); ?>">
            <input type="hidden" name="xc_form" value="1">

            <!-- 验证码 -->
            <section class="xc-card" role="tabpanel" id="xc-panel-captcha" aria-labelledby="xc-tab-captcha" data-tab="captcha">
                <div class="xc-card-head">
                    <h2><?php _e('验证码'); ?></h2>
                    <p><?php _e('评论、登录、注册使用；找回密码默认也跟随这里'); ?></p>
                </div>
                <div class="xc-row">
                    <span class="xc-row-label" id="xc-l-pages"><?php _e('应用到'); ?></span>
                    <div class="xc-chips" role="group" aria-labelledby="xc-l-pages">
                        <label class="xc-pill"><input type="checkbox" name="pages[]" value="comments"<?php echo $checked(in_array('comments', $pages, true)); ?>><span><?php _e('评论'); ?></span></label>
                        <label class="xc-pill"><input type="checkbox" name="pages[]" value="login"<?php echo $checked(in_array('login', $pages, true)); ?>><span><?php _e('登录和注册'); ?></span></label>
                    </div>
                </div>
                <div class="xc-row">
                    <span class="xc-row-label" id="xc-l-skip"><?php _e('管理员评论免验证'); ?></span>
                    <?php echo $switch('isAuthorUncheck', 'xc-l-skip'); ?>
                </div>
                <div class="xc-block">
                    <span class="xc-row-label" id="xc-l-tool"><?php _e('验证工具'); ?></span>
                    <div class="xc-tiles" role="radiogroup" aria-labelledby="xc-l-tool">
                        <?php foreach ($tools as $id => list($label, $note)): ?>
                        <label class="xc-tile"><input type="radio" name="captchaChoosen" value="<?php echo $e($id); ?>"<?php echo $checked($s['captchaChoosen'] === $id); ?>><span class="xc-tile-name"><?php echo $e($label); ?></span><span class="xc-tile-note"><?php echo $e($note); ?></span></label>
                        <?php endforeach; ?>
                    </div>
                    <?php echo $errorSlot('captchaChoosen'); ?>
                </div>
                <div class="xc-block xc-grid">
                    <?php echo $textField('capEndpoint', _t('Cap 服务地址'), ['wide' => true, 'type' => 'url', 'placeholder' => 'https://cap.example.com', 'attrs' => 'data-tools="cap"', 'hint' => _t('Cap Standalone 的根地址，不含站点 key')]); ?>
                    <?php echo $textField('captchaId', '<span data-label-for-tool>' . _t('站点 key / ID') . '</span>', ['attrs' => 'data-tools="cap hcaptcha cloudflare recaptcha geetest"']); ?>
                    <?php echo $secretField('secretKey', _t('密钥'), ['attrs' => 'data-tools="cap hcaptcha cloudflare recaptcha geetest"']); ?>
                    <div class="xc-field" data-tools="hcaptcha cloudflare recaptcha">
                        <label for="xc-widgetColor"><?php _e('颜色'); ?></label>
                        <select id="xc-widgetColor" name="widgetColor">
                            <?php foreach (['auto' => _t('自动'), 'light' => _t('浅色'), 'dark' => _t('深色')] as $v => $l): ?>
                            <option value="<?php echo $v; ?>"<?php echo $s['widgetColor'] === $v ? ' selected' : ''; ?>><?php echo $e($l); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="xc-field" data-tools="hcaptcha cloudflare recaptcha">
                        <label for="xc-widgetSize"><?php _e('尺寸'); ?></label>
                        <select id="xc-widgetSize" name="widgetSize">
                            <?php foreach (['normal' => _t('常规'), 'flexible' => _t('灵活'), 'compact' => _t('紧凑')] as $v => $l): ?>
                            <option value="<?php echo $v; ?>"<?php echo $s['widgetSize'] === $v ? ' selected' : ''; ?>><?php echo $e($l); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="xc-field" data-tools="geetest">
                        <label for="xc-dismod"><?php _e('展现形式'); ?></label>
                        <select id="xc-dismod" name="dismod">
                            <?php foreach (['float' => _t('浮动式'), 'embed' => _t('嵌入式'), 'popup' => _t('弹出框')] as $v => $l): ?>
                            <option value="<?php echo $v; ?>"<?php echo $s['dismod'] === $v ? ' selected' : ''; ?>><?php echo $e($l); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php echo $textField('cdnUrl', _t('组件脚本地址（可选）'), ['type' => 'url', 'placeholder' => _t('留空用默认'), 'attrs' => 'data-placeholder-cap="' . $e(_t('留空从 Cap 服务的 /assets/ 加载')) . '"']); ?>
                    <?php echo $textField('verifyUrl', _t('校验地址（可选）'), ['type' => 'url', 'placeholder' => _t('留空用默认'), 'attrs' => 'data-tools="cap hcaptcha cloudflare recaptcha"']); ?>
                    <p class="xc-note xc-wide" data-tools="altcha"><?php _e('Altcha 由插件自己出题和校验，不需要密钥；页面必须是 HTTPS。'); ?></p>
                    <div class="xc-actions xc-wide" data-tools="cap">
                        <button type="button" class="xc-btn" data-test-cap="main"><?php _e('检测 Cap 服务'); ?></button>
                        <div class="xc-result" data-result="cap-main" role="status" aria-live="polite"></div>
                    </div>
                </div>
                <div class="xc-foot">
                    <span class="xc-saved" role="status" aria-live="polite"></span>
                    <button type="submit" class="xc-btn xc-primary"><?php _e('保存设置'); ?></button>
                </div>
            </section>

            <!-- 找回密码 -->
            <section class="xc-card" role="tabpanel" id="xc-panel-passport" aria-labelledby="xc-tab-passport" data-tab="passport">
                <div class="xc-card-head xc-row-like">
                    <div>
                        <h2 id="xc-l-passport"><?php _e('找回密码'); ?></h2>
                        <p><?php _e('重置链接发到账号绑定的邮箱'); ?></p>
                    </div>
                    <?php echo $switch('passportEnabled', 'xc-l-passport'); ?>
                </div>
                <div data-when="passportEnabled">
                    <div class="xc-row">
                        <div class="xc-row-text">
                            <span class="xc-row-label"><?php _e('页面地址'); ?></span>
                            <a class="xc-url" href="<?php echo $e($forgotUrl); ?>" target="_blank" rel="noopener"><?php echo $e($forgotUrl); ?></a>
                        </div>
                        <button type="button" class="xc-btn" data-copy="<?php echo $e($forgotUrl); ?>"><?php _e('复制'); ?></button>
                    </div>
                    <div class="xc-row">
                        <span class="xc-row-label" id="xc-l-link"><?php _e('后台登录页显示「忘记密码」'); ?></span>
                        <?php echo $switch('passportLoginLink', 'xc-l-link'); ?>
                    </div>
                    <div class="xc-row">
                        <div class="xc-row-text">
                            <span class="xc-row-label" id="xc-l-pmode"><?php _e('验证码'); ?></span>
                            <span class="xc-hint" data-pmode-hint></span>
                        </div>
                        <div class="xc-seg" role="radiogroup" aria-labelledby="xc-l-pmode">
                            <?php foreach (['follow' => _t('跟随'), 'custom' => _t('单独配置'), 'none' => _t('不用')] as $v => $l): ?>
                            <label><input type="radio" name="passportCaptcha" value="<?php echo $v; ?>"<?php echo $checked($s['passportCaptcha'] === $v); ?>><span><?php echo $e($l); ?></span></label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <p class="xc-note is-warn" data-pmode="none"><?php _e('只剩按 IP 的频率限制在挡机器人，不建议'); ?></p>
                    <div class="xc-block xc-grid" data-pmode="custom">
                        <div class="xc-field">
                            <label for="xc-passportCaptchaChoosen"><?php _e('验证工具'); ?></label>
                            <select id="xc-passportCaptchaChoosen" name="passportCaptchaChoosen">
                                <?php foreach ($tools as $id => list($label)): ?>
                                <option value="<?php echo $e($id); ?>"<?php echo $s['passportCaptchaChoosen'] === $id ? ' selected' : ''; ?>><?php echo $e($label); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?php echo $textField('passportCapEndpoint', _t('Cap 服务地址'), ['type' => 'url', 'placeholder' => 'https://cap.example.com', 'attrs' => 'data-ptools="cap"']); ?>
                        <?php echo $textField('passportCaptchaId', _t('站点 key / ID'), ['attrs' => 'data-ptools="cap hcaptcha cloudflare recaptcha geetest"']); ?>
                        <?php echo $secretField('passportSecretKey', _t('密钥'), ['attrs' => 'data-ptools="cap hcaptcha cloudflare recaptcha geetest"']); ?>
                        <?php echo $textField('passportCdnUrl', _t('组件脚本地址（可选）'), ['type' => 'url', 'placeholder' => _t('留空用默认')]); ?>
                        <?php echo $textField('passportVerifyUrl', _t('校验地址（可选）'), ['type' => 'url', 'placeholder' => _t('留空用默认'), 'attrs' => 'data-ptools="cap hcaptcha cloudflare recaptcha"']); ?>
                        <div class="xc-actions xc-wide" data-ptools="cap">
                            <button type="button" class="xc-btn" data-test-cap="passport"><?php _e('检测 Cap 服务'); ?></button>
                            <div class="xc-result" data-result="cap-passport" role="status" aria-live="polite"></div>
                        </div>
                    </div>
                </div>
                <div class="xc-foot">
                    <span class="xc-saved" role="status" aria-live="polite"></span>
                    <button type="submit" class="xc-btn xc-primary"><?php _e('保存设置'); ?></button>
                </div>
            </section>

            <!-- 邮件 -->
            <section class="xc-card" role="tabpanel" id="xc-panel-mail" aria-labelledby="xc-tab-mail" data-tab="mail">
                <div class="xc-card-head">
                    <h2><?php _e('邮件'); ?></h2>
                    <p><?php _e('发送重置邮件用的 SMTP'); ?></p>
                </div>
                <div class="xc-block xc-grid">
                    <?php echo $textField('passportSmtpHost', _t('服务器'), ['wide' => true, 'placeholder' => 'smtp.example.com']); ?>
                    <div class="xc-field">
                        <label for="xc-passportSmtpPort"><?php _e('端口'); ?></label>
                        <input id="xc-passportSmtpPort" name="passportSmtpPort" type="number" inputmode="numeric" min="1" max="65535" value="<?php echo $e($s['passportSmtpPort']); ?>" required>
                        <?php echo $errorSlot('passportSmtpPort'); ?>
                    </div>
                    <div class="xc-field">
                        <label for="xc-passportSmtpSecure"><?php _e('加密'); ?></label>
                        <select id="xc-passportSmtpSecure" name="passportSmtpSecure">
                            <?php foreach (['ssl' => 'SSL', 'tls' => 'STARTTLS', 'none' => _t('不加密')] as $v => $l): ?>
                            <option value="<?php echo $v; ?>"<?php echo $s['passportSmtpSecure'] === $v ? ' selected' : ''; ?>><?php echo $e($l); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php echo $textField('passportSmtpUser', _t('帐号'), ['placeholder' => _t('通常就是发件邮箱')]); ?>
                    <?php echo $secretField('passportSmtpPassword', _t('密码'), ['empty' => _t('密码或授权码')]); ?>
                    <?php echo $textField('passportMailFrom', _t('发件人地址'), ['type' => 'email', 'placeholder' => _t('留空用帐号')]); ?>
                    <?php echo $textField('passportMailFromName', _t('发件人名称'), ['placeholder' => _t('留空用站点名称')]); ?>
                    <?php echo $textField('passportMailSubject', _t('标题'), ['wide' => true]); ?>
                    <div class="xc-field xc-wide">
                        <label for="xc-passportMailTemplate"><?php _e('内容（HTML）'); ?></label>
                        <textarea id="xc-passportMailTemplate" name="passportMailTemplate" rows="6" spellcheck="false" data-default="<?php echo $e(XCaptcha_Config::defaultMailTemplate()); ?>"><?php echo $e($s['passportMailTemplate']); ?></textarea>
                        <div class="xc-chips xc-placeholders">
                            <?php foreach (['{username}', '{sitename}', '{siteUrl}', '{requestTime}', '{resetLink}', '{expireMinutes}', '{ip}'] as $ph): ?>
                            <button type="button" class="xc-code" data-insert="<?php echo $e($ph); ?>"><?php echo $e($ph); ?></button>
                            <?php endforeach; ?>
                            <button type="button" class="xc-link" data-reset-template><?php _e('恢复默认'); ?></button>
                        </div>
                        <?php echo $errorSlot('passportMailTemplate'); ?>
                    </div>
                </div>
                <div class="xc-foot">
                    <span class="xc-saved" role="status" aria-live="polite"></span>
                    <button type="button" class="xc-btn" data-test-mail><?php _e('发测试邮件给我'); ?></button>
                    <button type="submit" class="xc-btn xc-primary"><?php _e('保存设置'); ?></button>
                </div>
            </section>

            <!-- 安全 -->
            <section class="xc-card" role="tabpanel" id="xc-panel-security" aria-labelledby="xc-tab-security" data-tab="security">
                <div class="xc-card-head">
                    <h2><?php _e('安全'); ?></h2>
                    <p><?php _e('只影响找回密码；验证码另外生效'); ?></p>
                </div>
                <div class="xc-row">
                    <div class="xc-row-text">
                        <label class="xc-row-label" for="xc-passportTokenTtl"><?php _e('重置链接有效期'); ?></label>
                        <span class="xc-hint"><?php _e('过期后链接作废'); ?></span>
                    </div>
                    <span class="xc-inline"><?php echo $numberInput('passportTokenTtl', _t('重置链接有效期（分钟）')); ?><?php _e('分钟'); ?></span>
                    <?php echo $errorSlot('passportTokenTtl'); ?>
                </div>
                <div class="xc-row">
                    <div class="xc-row-text">
                        <label class="xc-row-label" for="xc-passportMailCooldown"><?php _e('同一账号发信间隔'); ?></label>
                        <span class="xc-hint"><?php _e('间隔内再申请不发信，也不提示；0 为不限制'); ?></span>
                    </div>
                    <span class="xc-inline"><?php echo $numberInput('passportMailCooldown', _t('同一账号发信间隔（分钟）')); ?><?php _e('分钟'); ?></span>
                    <?php echo $errorSlot('passportMailCooldown'); ?>
                </div>
                <div class="xc-row">
                    <div class="xc-row-text">
                        <span class="xc-row-label" id="xc-l-rate"><?php _e('按 IP 限制提交频率'); ?></span>
                        <span class="xc-inline xc-hint" data-when="passportRateLimit">
                            <?php _e('10 分钟内超过'); ?><?php echo $numberInput('passportRateLimitAttempts', _t('10 分钟内最多提交次数')); ?><?php _e('次，封禁'); ?><?php echo $numberInput('passportRateLimitLock', _t('封禁时长（分钟）')); ?><?php _e('分钟'); ?>
                        </span>
                        <?php echo $errorSlot('passportRateLimitAttempts'); ?><?php echo $errorSlot('passportRateLimitLock'); ?>
                    </div>
                    <?php echo $switch('passportRateLimit', 'xc-l-rate'); ?>
                </div>
                <div class="xc-row">
                    <div class="xc-row-text">
                        <span class="xc-row-label" id="xc-l-cx"><?php _e('新密码复杂度'); ?></span>
                        <span class="xc-inline xc-hint">
                            <span data-when="passportPasswordComplexity"><?php _e('要求大小写字母、数字、特殊字符，'); ?></span><?php _e('至少'); ?><?php echo $numberInput('passportPasswordMinLength', _t('新密码最短长度')); ?><?php _e('位'); ?>
                        </span>
                        <?php echo $errorSlot('passportPasswordMinLength'); ?>
                    </div>
                    <?php echo $switch('passportPasswordComplexity', 'xc-l-cx'); ?>
                </div>
                <div class="xc-block">
                    <div class="xc-field">
                        <label for="xc-passportHmacKey"><?php _e('HMAC 密钥'); ?></label>
                        <div class="xc-inline-field">
                            <input id="xc-passportHmacKey" name="passportHmacKey" type="password" value="" data-secret="passportHmacKey" autocomplete="new-password" spellcheck="false"
                                placeholder="<?php echo $e($secretSet['passportHmacKey'] ? _t('已设置，留空不修改') : _t('留空则由站点密钥派生（推荐）')); ?>">
                            <?php if ($secretSet['passportHmacKey']): ?>
                            <label class="xc-pill"><input type="checkbox" name="clear[passportHmacKey]" value="1"><span><?php _e('改回自动派生'); ?></span></label>
                            <?php endif; ?>
                        </div>
                        <p class="xc-hint"><?php _e('改动后，已发出的重置链接全部失效'); ?></p>
                        <?php echo $errorSlot('passportHmacKey'); ?>
                    </div>
                </div>
                <p class="xc-note is-accent"><?php echo _t('插件看到的你的 IP：%s，和你实际的出口 IP 一致就说明真实 IP 识别正常', '<strong>' . $e($request->getIp()) . '</strong>'); ?></p>
                <div class="xc-foot">
                    <span class="xc-saved" role="status" aria-live="polite"></span>
                    <button type="submit" class="xc-btn xc-primary"><?php _e('保存设置'); ?></button>
                </div>
            </section>
        </form>

            <!-- 记录 -->
            <div class="xc-stack" role="tabpanel" id="xc-panel-logs" aria-labelledby="xc-tab-logs" data-tab="logs">
                <section class="xc-card" aria-labelledby="xc-h-fails">
                    <div class="xc-card-head">
                        <h2 id="xc-h-fails"><?php _e('IP 频率与封禁'); ?></h2>
                        <p><?php _e('最近 25 个提交过找回密码的 IP，封禁中的在最前'); ?></p>
                    </div>
                    <?php if (!$fails): ?>
                    <p class="xc-empty"><?php _e('没有记录'); ?></p>
                    <?php else: ?>
                    <ul class="xc-list">
                        <?php foreach ($fails as $fail):
                            $locked = (int) $fail['locked_until'] > $now; ?>
                        <li>
                            <span class="xc-mono xc-list-main"><?php echo $e($fail['ip']); ?></span>
                            <span class="xc-list-meta">
                                <span class="xc-tag <?php echo $locked ? 'is-danger' : 'is-ok'; ?>" data-status><?php echo $e($locked ? _t('封禁中 · 还剩约 %d 分钟', max(1, (int) ceil(((int) $fail['locked_until'] - $now) / 60))) : _t('正常')); ?></span>
                                <span><?php echo $e(_t('近期 %d 次 · 最后 %s', (int) $fail['attempts'], $formatTime($fail['last_attempt']))); ?></span>
                            </span>
                            <?php if ($locked): ?>
                            <button type="submit" form="xc-unblock" class="xc-btn xc-danger" name="ip" value="<?php echo $e($fail['ip']); ?>" data-unblock="<?php echo $e($fail['ip']); ?>"><?php _e('解封'); ?></button>
                            <?php endif; ?>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                    <?php endif; ?>
                </section>
                <section class="xc-card" aria-labelledby="xc-h-logs">
                    <div class="xc-card-head">
                        <h2 id="xc-h-logs"><?php _e('最近的找回密码申请'); ?></h2>
                        <p><?php _e('保留 30 天；不记录访客填写的内容'); ?></p>
                    </div>
                    <?php if (!$logs): ?>
                    <p class="xc-empty"><?php _e('没有记录'); ?></p>
                    <?php else: ?>
                    <ul class="xc-list">
                        <?php foreach ($logs as $log):
                            list($label, $tone) = $events[$log['event']] ?? [$log['event'], 'muted']; ?>
                        <li>
                            <span class="xc-list-main"><span class="xc-tag is-<?php echo $e($tone); ?>"><?php echo $e($label); ?></span></span>
                            <span class="xc-list-meta"><?php echo $e($formatTime($log['created_at'])); ?> · <?php echo $e($log['name'] !== '' ? $log['name'] : '—'); ?> · <span class="xc-mono"><?php echo $e($log['ip']); ?></span></span>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                    <?php endif; ?>
                </section>
            </div>
            <form id="xc-unblock" method="post" action="<?php echo $e(Typecho_Common::url('/action/' . XCaptcha_Settings::ADMIN_ACTION . '?do=unblock', $options->index)); ?>" hidden>
                <input type="hidden" name="_" value="<?php echo $e($token); ?>">
            </form>
        </div>
    </div>
</div>
</main>
<?php
include $adminDir . 'copyright.php';
include $adminDir . 'common-js.php';
?>
<script src="<?php echo $e($staticUrl . 'admin.js?v=' . $assetVersion('admin.js')); ?>"></script>
<?php
include $adminDir . 'footer.php';
