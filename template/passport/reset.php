<?php if (!defined('__TYPECHO_ROOT_DIR__')) exit; ?>
<?php
/**
 * 设置新密码
 * 可用变量：$options、$captcha（找回密码页的验证码配置档）、$config、$title、$action
 */
$minLength = $config->getPassportPasswordMinLength();
include __DIR__ . '/header.php';
?>
<div class="typecho-login-wrap">
    <div class="typecho-login">
        <h1><a href="<?php $options->siteUrl(); ?>"><?php $options->title(); ?></a></h1>
        <p class="description"><?php
            if ($config->isPassportPasswordComplexityEnabled()) {
                _e('新密码至少 %d 位，需同时包含大小写字母、数字和特殊字符。', $minLength);
            } else {
                _e('新密码至少 %d 位。', $minLength);
            }
        ?></p>
        <form action="<?php echo htmlspecialchars($action, ENT_QUOTES, 'UTF-8'); ?>" method="post" name="reset" role="form">
            <p>
                <label for="password" class="sr-only"><?php _e('新密码'); ?></label>
                <input type="password" id="password" name="password" placeholder="<?php _e('新密码'); ?>" class="text-l w-100" autocomplete="new-password" minlength="<?php echo (int) $minLength; ?>" autofocus required />
            </p>
            <p>
                <label for="confirm" class="sr-only"><?php _e('确认新密码'); ?></label>
                <input type="password" id="confirm" name="confirm" placeholder="<?php _e('确认新密码'); ?>" class="text-l w-100" autocomplete="new-password" minlength="<?php echo (int) $minLength; ?>" required />
            </p>
            <p class="submit">
                <button type="submit" class="btn btn-l w-100 primary"><?php _e('设置新密码'); ?></button>
            </p>
        </form>

        <p class="more-link">
            <a href="<?php $options->siteUrl(); ?>"><?php _e('返回首页'); ?></a>
            &bull;
            <a href="<?php echo htmlspecialchars($options->loginUrl, ENT_QUOTES, 'UTF-8'); ?>"><?php _e('用户登录'); ?></a>
        </p>
    </div>
</div>
<?php
include __ADMIN_DIR__ . '/common-js.php';
// 验证码组件：插到提交按钮前面，验证通过前按钮不可用
XCaptcha_Render::renderPassportForm($captcha);
include __ADMIN_DIR__ . '/footer.php';
