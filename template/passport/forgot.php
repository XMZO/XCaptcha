<?php if (!defined('__TYPECHO_ROOT_DIR__')) exit; ?>
<?php
/**
 * 申请找回密码
 * 可用变量：$options、$captcha（找回密码页的验证码配置档）、$config、$title、$action
 */
include __DIR__ . '/header.php';
?>
<div class="typecho-login-wrap">
    <div class="typecho-login">
        <h1><a href="<?php $options->siteUrl(); ?>"><?php $options->title(); ?></a></h1>
        <p class="description"><?php _e('填写用户名或邮箱，重置密码的链接会发到该账号绑定的邮箱。'); ?></p>
        <form action="<?php echo htmlspecialchars($action, ENT_QUOTES, 'UTF-8'); ?>" method="post" name="forgot" role="form">
            <p>
                <label for="account" class="sr-only"><?php _e('用户名或邮箱'); ?></label>
                <input type="text" id="account" name="account" placeholder="<?php _e('用户名或邮箱'); ?>" class="text-l w-100" autocomplete="username" autocapitalize="none" autocorrect="off" spellcheck="false" maxlength="200" autofocus required />
            </p>
            <p class="submit">
                <button type="submit" class="btn btn-l w-100 primary"><?php _e('发送重置邮件'); ?></button>
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
