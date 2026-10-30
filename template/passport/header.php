<?php if (!defined('__TYPECHO_ROOT_DIR__')) exit; ?>
<?php
/**
 * 找回密码页面的头部：套用后台登录页的样式
 * 由 XCaptcha_Passport::renderPage 引入，可用变量：$options、$title
 */
if (!defined('__TYPECHO_ADMIN__')) {
    define('__TYPECHO_ADMIN__', true);
}
if (!defined('__ADMIN_DIR__')) {
    define('__ADMIN_DIR__', __TYPECHO_ROOT_DIR__ . __TYPECHO_ADMIN_DIR__);
}
?><!DOCTYPE HTML>
<html>
    <head>
        <meta charset="<?php $options->charset(); ?>">
        <meta name="renderer" content="webkit">
        <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no">
        <title><?php echo htmlspecialchars($title . ' - ' . $options->title, ENT_QUOTES, 'UTF-8'); ?></title>
        <meta name="robots" content="noindex, nofollow">
        <link rel="stylesheet" href="<?php $options->adminStaticUrl('css', 'normalize.css'); ?>">
        <link rel="stylesheet" href="<?php $options->adminStaticUrl('css', 'grid.css'); ?>">
        <link rel="stylesheet" href="<?php $options->adminStaticUrl('css', 'style.css'); ?>">
        <style>
            .typecho-login h1 a { font-size: 24px; text-decoration: none; }
            .typecho-login .description { margin: -10px 0 20px; color: #999; font-size: 13px; line-height: 1.5; }
        </style>
    </head>
    <body class="body-100">
