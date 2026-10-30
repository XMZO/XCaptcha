<?php

/**
 * 用 PHPMailer 经 SMTP 发信（找回密码和后台的测试邮件共用）
 */
class XCaptcha_Mailer
{
    /**
     * @param array  $smtp      host, port, secure(ssl/tls/none), user, password, from, fromName
     * @param string $toAddress 收件地址
     * @param string $toName    收件人名称
     * @param string $subject   标题
     * @param string $html      HTML 正文
     * @param string $text      纯文本正文
     * @return string|null 发不出去时的原因，发出时为 null
     */
    public static function send(array $smtp, $toAddress, $toName, $subject, $html, $text)
    {
        $from = $smtp['from'] !== '' ? $smtp['from'] : $smtp['user'];
        if ($smtp['host'] === '') {
            return _t('没有填 SMTP 服务器');
        }
        if ($from === '') {
            return _t('没有发件人地址：填 SMTP 帐号或发件人地址');
        }

        require_once dirname(__DIR__) . '/lib/PHPMailer/Exception.php';
        require_once dirname(__DIR__) . '/lib/PHPMailer/PHPMailer.php';
        require_once dirname(__DIR__) . '/lib/PHPMailer/SMTP.php';

        try {
            $mail = new PHPMailer\PHPMailer\PHPMailer(true);
            $mail->CharSet = PHPMailer\PHPMailer\PHPMailer::CHARSET_UTF8;
            $mail->isSMTP();
            $mail->Host = $smtp['host'];
            $mail->Port = (int) $smtp['port'];
            $mail->Timeout = 15;
            if ($smtp['secure'] === 'none') {
                $mail->SMTPSecure = '';
                $mail->SMTPAutoTLS = false;
            } else {
                $mail->SMTPSecure = $smtp['secure'] === 'tls'
                    ? PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS
                    : PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
            }
            $mail->SMTPAuth = $smtp['user'] !== '';
            if ($mail->SMTPAuth) {
                $mail->Username = $smtp['user'];
                $mail->Password = $smtp['password'];
            }
            $mail->setFrom($from, $smtp['fromName']);
            $mail->addAddress($toAddress, $toName);
            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body = $html;
            $mail->AltBody = $text;
            $mail->send();
            return null;
        } catch (Throwable $e) {
            return $e->getMessage();
        }
    }

    /**
     * HTML 正文转纯文本（给不显示 HTML 的邮件客户端）
     * @param string $html
     * @return string
     */
    public static function toText($html)
    {
        $text = preg_replace('/<(br|hr)\s*\/?>|<\/(p|h[1-6]|li|tr|div)>/i', "\n", $html);
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES, 'UTF-8');
        $text = preg_replace("/[ \t]+\n/", "\n", $text);
        return trim(preg_replace("/\n{3,}/", "\n\n", $text));
    }
}
