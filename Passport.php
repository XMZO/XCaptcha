<?php
if (!defined('__TYPECHO_ROOT_DIR__')) exit;

/**
 * 找回密码：/passport/forgot 申请重置邮件，/passport/reset 设置新密码
 *
 * 由 Passport 插件（GARFIELDTOM）合并而来，验证码改用 XCaptcha 自己的配置，
 * 数据表沿用它的表名，原有的封禁记录直接接着用。
 */
class XCaptcha_Passport extends Typecho_Widget
{
    /** 路由名和地址 */
    const ROUTE_FORGOT = 'xcaptcha_forgot';
    const ROUTE_RESET = 'xcaptcha_reset';
    const PATH_FORGOT = '/passport/forgot';
    const PATH_RESET = '/passport/reset';

    /** 数据表（不含前缀） */
    const TABLE_TOKENS = 'password_reset_tokens';
    const TABLE_FAILS = 'passport_fails';
    const TABLE_LOG = 'xcaptcha_passport_log';

    /** 频率限制的统计窗口（秒）：这么久没再提交就从头计数 */
    const FAIL_WINDOW = 600;

    /** 频率限制记录、申请记录保留天数 */
    const FAIL_KEEP_DAYS = 30;

    /** 申请记录的事件 */
    const EVENT_SENT = 'sent';
    const EVENT_COOLDOWN = 'cooldown';
    const EVENT_NOMATCH = 'nomatch';
    const EVENT_MAIL_FAILED = 'mail_failed';
    const EVENT_CAPTCHA_FAILED = 'captcha_failed';
    const EVENT_RATE_LOCKED = 'rate_locked';
    const EVENT_RESET = 'reset';

    /** 新密码最长字节数，再长没有意义，还会拖慢哈希 */
    const PASSWORD_MAX_LENGTH = 256;

    /** @var XCaptcha_Config 插件配置 */
    private $config;

    /** @var XCaptcha_Config 找回密码页用的验证码配置档 */
    private $captcha;

    /** @var Typecho_Db */
    private $db;

    /** @var Widget_Options */
    private $options;

    /** @var Widget_Notice */
    private $notice;

    public function __construct($request, $response, $params = null)
    {
        parent::__construct($request, $response, $params);
        $this->options = Typecho_Widget::widget('Widget_Options');
        $this->notice = Typecho_Widget::widget('Widget_Notice');
        $this->db = Typecho_Db::get();
        $this->config = new XCaptcha_Config();
        $this->captcha = new XCaptcha_Config(XCaptcha_Config::PROFILE_PASSPORT);
    }

    public function execute()
    {
    }

    /* ---------- 页面 ---------- */

    /**
     * 申请找回密码
     */
    public function doForgot()
    {
        $this->prepare();

        if ($this->request->isPost()) {
            $this->handleForgot();
            // 提示已写进 cookie，跳回本页用 GET 显示，刷新不会重复提交
            $this->response->redirect($this->forgotUrl());
        }

        $this->renderPage('forgot', _t('找回密码'), $this->forgotUrl());
    }

    /**
     * 用邮件里的链接设置新密码
     */
    public function doReset()
    {
        $this->prepare();

        $token = (string) $this->request->get('token', '');
        $signature = (string) $this->request->get('signature', '');
        $record = $this->findToken($token, $signature);
        if (!$record) {
            $this->notice->set(_t('重置链接无效或已过期，请重新申请。'), 'error');
            $this->response->redirect($this->forgotUrl());
        }

        if ($this->request->isPost()) {
            if ($this->handleReset($record)) {
                $this->notice->set(_t('密码已重置，请用新密码登录。'), 'success');
                $this->response->redirect($this->options->loginUrl);
            }
            $this->response->redirect($this->resetUrl($token, $signature));
        }

        $this->renderPage('reset', _t('重置密码'), $this->resetUrl($token, $signature));
    }

    /**
     * 两个页面共同的开头：功能没开就 404，清理过期数据，页面不缓存
     */
    private function prepare()
    {
        if (!$this->config->isPassportEnabled()) {
            throw new Typecho_Widget_Exception(_t('找回密码功能未开启'), 404);
        }
        $this->cleanup();
        if (!headers_sent()) {
            header('Cache-Control: no-store, max-age=0');
        }
    }

    /**
     * 输出页面模板
     * @param string $name   模板名（forgot / reset）
     * @param string $title  页面标题
     * @param string $action 表单提交地址
     */
    private function renderPage($name, $title, $action)
    {
        $options = $this->options;
        $config = $this->config;
        $captcha = $this->captcha;
        require __DIR__ . '/template/passport/' . $name . '.php';
    }

    /* ---------- 申请 ---------- */

    private function handleForgot()
    {
        try {
            $this->checkRateLimit();
            if ($errors = $this->forgotForm()->validate()) {
                throw new XCaptcha_Passport_Exception(reset($errors));
            }
            $this->checkCaptcha();
            $this->sendResetMail((string) $this->request->get('account', ''));
        } catch (XCaptcha_Passport_Exception $e) {
            $this->notice->set($e->getMessage(), 'error');
            return;
        }
        // 账号存不存在、有没有真的发信都是这句，免得被用来探测账号
        $this->notice->set(_t('如果该账号存在，重置邮件已经发出，请查收邮箱（包括垃圾邮件）。'), 'success');
    }

    /**
     * 给账号发重置邮件；账号不存在或刚发过就什么都不做
     * @param string $account 用户名或邮箱
     * @throws XCaptcha_Passport_Exception 邮件发不出去
     */
    private function sendResetMail($account)
    {
        // 访客看到的提示都一样，管理员排查「没收到信」只能看这里的日志；不记访客填的内容
        $user = $this->findUser($account);
        if (!$user) {
            error_log('XCaptcha: 找回密码申请没有匹配的账号，未发信');
            $this->log(self::EVENT_NOMATCH);
            return;
        }

        $now = (int) $this->options->gmtTime;
        $cooldown = $this->config->getPassportMailCooldown();
        if ($cooldown > 0) {
            $recent = $this->db->fetchRow($this->db->select('created_at')->from('table.' . self::TABLE_TOKENS)
                ->where('uid = ? AND created_at > ?', $user['uid'], $now - $cooldown)->limit(1));
            if ($recent) {
                error_log('XCaptcha: 找回密码 uid=' . $user['uid'] . ' 还在发信冷却期内，未重复发信');
                $this->log(self::EVENT_COOLDOWN, $user['uid']);
                return;
            }
        }

        // 链接里是原始令牌，库里只存哈希：数据库泄露也拿不到能用的链接
        $token = bin2hex(random_bytes(32));
        $hash = self::hashToken($token);
        $this->db->query($this->db->insert('table.' . self::TABLE_TOKENS)->rows([
            'token' => $hash,
            'uid' => $user['uid'],
            'created_at' => $now,
            'used' => 0,
        ]));

        $link = $this->resetUrl($token, $this->signature($hash, $user['uid'], $now));
        if (!$this->deliverMail($user, $link)) {
            // 没发出去：作废这枚令牌，否则冷却期会拦住重试；也不算访客的一次失败
            $this->db->query($this->db->delete('table.' . self::TABLE_TOKENS)->where('token = ?', $hash));
            $this->undoAttempt();
            $this->log(self::EVENT_MAIL_FAILED, $user['uid']);
            throw new XCaptcha_Passport_Exception(_t('邮件发送失败，请稍后再试或联系管理员。'));
        }
        error_log('XCaptcha: 已向 uid=' . $user['uid'] . ' 发送找回密码邮件');
        $this->log(self::EVENT_SENT, $user['uid']);
    }

    /**
     * 按用户名或邮箱找用户
     * @param string $account
     * @return array|null uid, name, mail；没有或没填邮箱时为 null
     */
    private function findUser($account)
    {
        // 中文输入法容易打出全角＠
        $account = trim(str_replace('＠', '@', $account));
        if ($account === '' || strlen($account) > 200) {
            return null;
        }
        $field = strpos($account, '@') !== false ? 'mail' : 'name';
        $user = $this->db->fetchRow($this->db->select('uid', 'name', 'mail')->from('table.users')
            ->where($field . ' = ?', $account)->limit(1));
        if (!$user) {
            // 手机键盘常把首字母自动改成大写，邮箱也不该区分大小写：精确匹配不到时不区分大小写再找一次，
            // 只有恰好一个账号对得上才算，免得 Bob 和 bob 这样的两个账号选错人
            $rows = $this->db->fetchAll($this->db->select('uid', 'name', 'mail')->from('table.users')
                ->where('LOWER(' . $field . ') = ?', strtolower($account))->limit(2));
            $user = count($rows) === 1 ? $rows[0] : null;
        }
        if (!$user || empty($user['mail'])) {
            return null;
        }
        return $user;
    }

    /**
     * 用 PHPMailer 经 SMTP 发出重置邮件
     * @param array  $user 用户
     * @param string $link 重置链接
     * @return boolean 是否发出
     */
    private function deliverMail(array $user, $link)
    {
        $smtp = $this->config->getPassportSmtp();
        $siteName = (string) $this->options->title;
        $requestTime = (new Typecho_Date((int) $this->options->gmtTime))->format('Y-m-d H:i:s');
        $expireMinutes = (int) ($this->config->getPassportTokenTtl() / 60);
        $raw = [
            '{username}' => (string) $user['name'],
            '{sitename}' => $siteName,
            '{siteUrl}' => (string) $this->options->siteUrl,
            '{requestTime}' => $requestTime,
            '{resetLink}' => $link,
            '{expireMinutes}' => (string) $expireMinutes,
            '{ip}' => $this->request->getIp(),
        ];
        $escaped = [];
        foreach ($raw as $key => $value) {
            $escaped[$key] = htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
        }

        $body = strtr($this->config->getPassportMailTemplate(), $escaped);
        // 纯文本版给不显示 HTML 的邮箱看；模板是表格排版，从 HTML 去标签会很乱，所以单独写
        $plain = strtr("{username}，你好：\n\n"
            . "我们在 {requestTime} 收到了重置你在 {sitename} 的账号密码的申请。"
            . "请在 {expireMinutes} 分钟内打开下面的链接设置新密码，链接只能用一次：\n\n"
            . "{resetLink}\n\n"
            . "如果这不是你本人的操作，请忽略这封邮件，你的密码不会改变。\n"
            . "本次申请来自 IP：{ip}\n", $raw);
        if ($smtp['fromName'] === '') {
            $smtp['fromName'] = $siteName;
        }
        $subject = strtr($this->config->getPassportMailSubject(), [
            '{sitename}' => $siteName,
            '{username}' => (string) $user['name'],
        ]);

        $error = XCaptcha_Mailer::send($smtp, $user['mail'], (string) $user['name'], $subject, $body, $plain);
        if ($error !== null) {
            error_log('XCaptcha: 发送找回密码邮件失败: ' . $error);
            return false;
        }
        return true;
    }

    /* ---------- 重置 ---------- */

    /**
     * 核对链接里的令牌和签名
     * @param string $token     链接里的原始令牌
     * @param string $signature 链接里的签名
     * @return array|null 令牌记录（token, uid, created_at, used）
     */
    private function findToken($token, $signature)
    {
        if (!preg_match('/^[0-9a-f]{64}$/', $token) || !preg_match('/^[0-9a-f]{64}$/', $signature)) {
            return null;
        }
        $hash = self::hashToken($token);
        $record = $this->db->fetchRow($this->db->select()->from('table.' . self::TABLE_TOKENS)->where('token = ?', $hash));
        if (!$record || (int) $record['used'] !== 0) {
            return null;
        }
        if ((int) $record['created_at'] + $this->config->getPassportTokenTtl() < (int) $this->options->gmtTime) {
            return null;
        }
        if (!hash_equals($this->signature($hash, (int) $record['uid'], (int) $record['created_at']), $signature)) {
            return null;
        }
        return $record;
    }

    /**
     * @param array $record 令牌记录
     * @return boolean 密码是否已改
     */
    private function handleReset(array $record)
    {
        try {
            $this->checkRateLimit();
            if ($errors = $this->resetForm()->validate()) {
                throw new XCaptcha_Passport_Exception(reset($errors));
            }
            $password = (string) $this->request->get('password', '');
            $this->checkPassword($password, (int) $record['uid']);
            $this->checkCaptcha((int) $record['uid']);
        } catch (XCaptcha_Passport_Exception $e) {
            $this->notice->set($e->getMessage(), 'error');
            return false;
        }

        $hasherClass = class_exists('Utils\PasswordHash') ? 'Utils\PasswordHash' : 'PasswordHash';
        $hasher = new $hasherClass(8, true);
        // 顺便换掉 authCode：所有浏览器里已登录的会话一起失效，拿到旧会话的人不能继续用
        $this->db->query($this->db->update('table.users')->rows([
            'password' => $hasher->hashPassword($password),
            'authCode' => bin2hex(random_bytes(16)),
        ])->where('uid = ?', $record['uid']));
        $this->db->query($this->db->delete('table.' . self::TABLE_TOKENS)->where('uid = ?', $record['uid']));
        $this->log(self::EVENT_RESET, (int) $record['uid']);
        return true;
    }

    /**
     * 新密码的长度和复杂度
     * @throws XCaptcha_Passport_Exception
     */
    private function checkPassword($password, $uid)
    {
        $minLength = $this->config->getPassportPasswordMinLength();
        if (strlen($password) < $minLength) {
            throw new XCaptcha_Passport_Exception(_t('密码长度不能少于 %d 位', $minLength));
        }
        if (strlen($password) > self::PASSWORD_MAX_LENGTH) {
            throw new XCaptcha_Passport_Exception(_t('密码太长了'));
        }
        if ($this->config->isPassportPasswordComplexityEnabled()) {
            $missing = [];
            if (!preg_match('/[A-Z]/', $password)) $missing[] = _t('大写字母');
            if (!preg_match('/[a-z]/', $password)) $missing[] = _t('小写字母');
            if (!preg_match('/[0-9]/', $password)) $missing[] = _t('数字');
            if (!preg_match('/[^A-Za-z0-9]/', $password)) $missing[] = _t('特殊字符');
            if ($missing) {
                throw new XCaptcha_Passport_Exception(_t('密码还需要包含：%s', implode('、', $missing)));
            }
        }
        $user = $this->db->fetchRow($this->db->select('name', 'mail')->from('table.users')->where('uid = ?', $uid));
        if ($user && (strcasecmp($password, (string) $user['name']) === 0 || strcasecmp($password, (string) $user['mail']) === 0)) {
            throw new XCaptcha_Passport_Exception(_t('密码不能和用户名或邮箱相同'));
        }
    }

    /* ---------- 验证码、频率限制 ---------- */

    /**
     * 按找回密码的验证码配置校验本次提交
     * @param int $uid 重置密码时是链接对应的用户，申请时为 0
     * @throws XCaptcha_Passport_Exception
     */
    private function checkCaptcha($uid = 0)
    {
        if ($this->captcha->isCaptchaDisabled()) {
            return;
        }
        if (!$this->captcha->checkKeys()) {
            // 配置不全时宁可拒绝，也不放过没有验证码的提交
            throw new XCaptcha_Passport_Exception(_t('验证码尚未配置完整，请联系管理员。'));
        }
        if (!XCaptcha_Plugin::verifySubmission($this->captcha)) {
            $this->log(self::EVENT_CAPTCHA_FAILED, $uid);
            throw new XCaptcha_Passport_Exception(_t('人机验证未通过，请重试。'));
        }
    }

    /**
     * 每次提交都计一次；窗口内超过上限就封禁这个 IP 一段时间
     * @throws XCaptcha_Passport_Exception 已被封禁
     */
    private function checkRateLimit()
    {
        if (!$this->config->isPassportRateLimitEnabled()) {
            return;
        }
        $ip = $this->request->getIp();
        $now = time();
        $table = 'table.' . self::TABLE_FAILS;
        $row = $this->db->fetchRow($this->db->select()->from($table)->where('ip = ?', $ip));

        if (!$row) {
            try {
                $this->db->query($this->db->insert($table)->rows([
                    'ip' => $ip, 'attempts' => 1, 'last_attempt' => $now, 'locked_until' => 0,
                ]));
            } catch (Typecho_Db_Exception $e) {
                // 同一 IP 两个请求同时首次提交，另一个已经插入了
            }
            return;
        }

        if ((int) $row['locked_until'] > $now) {
            throw new XCaptcha_Passport_Exception($this->lockedMessage((int) $row['locked_until'] - $now));
        }

        $attempts = ($now - (int) $row['last_attempt'] > self::FAIL_WINDOW) ? 1 : (int) $row['attempts'] + 1;
        $rows = ['attempts' => $attempts, 'last_attempt' => $now];
        if ($attempts >= $this->config->getPassportRateLimitAttempts()) {
            $lock = $this->config->getPassportRateLimitLock();
            $rows['attempts'] = 0;
            $rows['locked_until'] = $now + $lock;
            $this->db->query($this->db->update($table)->rows($rows)->where('ip = ?', $ip));
            $this->log(self::EVENT_RATE_LOCKED);
            throw new XCaptcha_Passport_Exception($this->lockedMessage($lock));
        }
        $this->db->query($this->db->update($table)->rows($rows)->where('ip = ?', $ip));
    }

    /**
     * 不是访客的错（比如邮件发不出去）时，把刚才计的那一次退回去
     */
    private function undoAttempt()
    {
        if (!$this->config->isPassportRateLimitEnabled()) {
            return;
        }
        $table = 'table.' . self::TABLE_FAILS;
        $ip = $this->request->getIp();
        $row = $this->db->fetchRow($this->db->select('attempts')->from($table)->where('ip = ?', $ip));
        if ($row && (int) $row['attempts'] > 0) {
            $this->db->query($this->db->update($table)->rows(['attempts' => (int) $row['attempts'] - 1])->where('ip = ?', $ip));
        }
    }

    private function lockedMessage($seconds)
    {
        $minutes = max(1, (int) ceil($seconds / 60));
        return _t('提交太频繁，已暂时限制，请 %d 分钟后再试。', $minutes);
    }

    /**
     * 删除过期、已用的令牌和太久以前的频率记录、申请记录
     */
    private function cleanup()
    {
        $now = (int) $this->options->gmtTime;
        $this->db->query($this->db->delete('table.' . self::TABLE_TOKENS)
            ->where('created_at < ? OR used = ?', $now - $this->config->getPassportTokenTtl(), 1));
        $this->db->query($this->db->delete('table.' . self::TABLE_FAILS)
            ->where('last_attempt < ? AND locked_until < ?', time() - self::FAIL_KEEP_DAYS * 86400, time()));
        try {
            $this->db->query($this->db->delete('table.' . self::TABLE_LOG)
                ->where('created_at < ?', time() - self::FAIL_KEEP_DAYS * 86400));
        } catch (Typecho_Db_Exception $e) {
            // 升级后还没保存过配置，记录表尚未建
        }
    }

    /**
     * 记一条申请记录，给后台「记录」页看；只记事件、用户和 IP，不记访客填的内容。
     * 记录失败不影响找回密码本身。
     * @param string $event EVENT_* 之一
     * @param int    $uid   相关用户，没有时为 0
     */
    private function log($event, $uid = 0)
    {
        try {
            $this->db->query($this->db->insert('table.' . self::TABLE_LOG)->rows([
                'created_at' => time(),
                'event' => $event,
                'uid' => (int) $uid,
                'ip' => substr($this->request->getIp(), 0, 45),
            ]));
        } catch (Exception $e) {
            error_log('XCaptcha: 写找回密码记录失败: ' . $e->getMessage());
        }
    }

    /* ---------- 表单 ---------- */

    public function forgotForm()
    {
        $form = new Typecho_Widget_Helper_Form(NULL, Typecho_Widget_Helper_Form::POST_METHOD);
        $account = new Typecho_Widget_Helper_Form_Element_Text('account', NULL, NULL, _t('用户名或邮箱'));
        $form->addInput($account);
        $account->addRule('required', _t('请填写用户名或邮箱'));
        $account->addRule('maxLength', _t('用户名或邮箱太长了'), 200);
        return $form;
    }

    public function resetForm()
    {
        $form = new Typecho_Widget_Helper_Form(NULL, Typecho_Widget_Helper_Form::POST_METHOD);
        $password = new Typecho_Widget_Helper_Form_Element_Password('password', NULL, NULL, _t('新密码'));
        $confirm = new Typecho_Widget_Helper_Form_Element_Password('confirm', NULL, NULL, _t('确认新密码'));
        $form->addInput($password);
        $form->addInput($confirm);
        $password->addRule('required', _t('请填写新密码'));
        $confirm->addRule('confirm', _t('两次输入的密码不一致'), 'password');
        return $form;
    }

    /* ---------- 工具 ---------- */

    private function forgotUrl()
    {
        return Typecho_Common::url(self::PATH_FORGOT, $this->options->index);
    }

    private function resetUrl($token, $signature)
    {
        return Typecho_Common::url(self::PATH_RESET . '?token=' . rawurlencode($token) . '&signature=' . rawurlencode($signature), $this->options->index);
    }

    private function signature($tokenHash, $uid, $createdAt)
    {
        return hash_hmac('sha256', $tokenHash . '|' . (int) $uid . '|' . (int) $createdAt, $this->config->getPassportHmacKey());
    }

    /**
     * 库里存的令牌形式
     * @param string $token 原始令牌
     * @return string
     */
    public static function hashToken($token)
    {
        return hash('sha256', $token);
    }

    /* ---------- 供 Plugin.php 调用：路由、数据表、后台封禁记录 ---------- */

    /**
     * 注册两条路由（已存在则覆盖，可重复调用）
     */
    public static function addRoutes()
    {
        Helper::addRoute(self::ROUTE_FORGOT, self::PATH_FORGOT, 'XCaptcha_Passport', 'doForgot');
        Helper::addRoute(self::ROUTE_RESET, self::PATH_RESET, 'XCaptcha_Passport', 'doReset');
    }

    public static function removeRoutes()
    {
        Helper::removeRoute(self::ROUTE_FORGOT);
        Helper::removeRoute(self::ROUTE_RESET);
    }

    /**
     * 建表（已存在则跳过）。令牌表和频率表沿用 Passport 插件的结构，MySQL、PostgreSQL、SQLite 都能用
     */
    public static function createTables()
    {
        $db = Typecho_Db::get();
        $prefix = $db->getPrefix();
        $tokens = $prefix . self::TABLE_TOKENS;
        $fails = $prefix . self::TABLE_FAILS;
        $log = $prefix . self::TABLE_LOG;
        $adapter = strtolower((string) $db->getAdapterName());

        if (strpos($adapter, 'mysql') !== false) {
            $db->query("CREATE TABLE IF NOT EXISTS `{$tokens}` (
                `token` VARCHAR(64) NOT NULL,
                `uid` INT(10) UNSIGNED NOT NULL,
                `created_at` INT(10) UNSIGNED NOT NULL,
                `used` TINYINT(1) NOT NULL DEFAULT 0,
                PRIMARY KEY (`token`),
                INDEX `uid` (`uid`),
                INDEX `created_at` (`created_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4", Typecho_Db::WRITE);
            $db->query("CREATE TABLE IF NOT EXISTS `{$fails}` (
                `ip` VARCHAR(45) NOT NULL,
                `attempts` INT(10) UNSIGNED NOT NULL DEFAULT 0,
                `last_attempt` INT(10) UNSIGNED NOT NULL,
                `locked_until` INT(10) UNSIGNED NOT NULL DEFAULT 0,
                PRIMARY KEY (`ip`),
                INDEX `locked_until` (`locked_until`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4", Typecho_Db::WRITE);
            $db->query("CREATE TABLE IF NOT EXISTS `{$log}` (
                `created_at` INT(10) UNSIGNED NOT NULL,
                `event` VARCHAR(20) NOT NULL,
                `uid` INT(10) UNSIGNED NOT NULL DEFAULT 0,
                `ip` VARCHAR(45) NOT NULL DEFAULT '',
                INDEX `created_at` (`created_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4", Typecho_Db::WRITE);
            return;
        }

        // SQLite 和 PostgreSQL：不加引号的标识符两边都认，索引单独建
        $db->query("CREATE TABLE IF NOT EXISTS {$tokens} (
            token VARCHAR(64) NOT NULL PRIMARY KEY,
            uid INTEGER NOT NULL,
            created_at INTEGER NOT NULL,
            used INTEGER NOT NULL DEFAULT 0
        )", Typecho_Db::WRITE);
        $db->query("CREATE INDEX IF NOT EXISTS {$tokens}_uid ON {$tokens} (uid)", Typecho_Db::WRITE);
        $db->query("CREATE INDEX IF NOT EXISTS {$tokens}_created_at ON {$tokens} (created_at)", Typecho_Db::WRITE);
        $db->query("CREATE TABLE IF NOT EXISTS {$fails} (
            ip VARCHAR(45) NOT NULL PRIMARY KEY,
            attempts INTEGER NOT NULL DEFAULT 0,
            last_attempt INTEGER NOT NULL,
            locked_until INTEGER NOT NULL DEFAULT 0
        )", Typecho_Db::WRITE);
        $db->query("CREATE INDEX IF NOT EXISTS {$fails}_locked_until ON {$fails} (locked_until)", Typecho_Db::WRITE);
        $db->query("CREATE TABLE IF NOT EXISTS {$log} (
            created_at INTEGER NOT NULL,
            event VARCHAR(20) NOT NULL,
            uid INTEGER NOT NULL DEFAULT 0,
            ip VARCHAR(45) NOT NULL DEFAULT ''
        )", Typecho_Db::WRITE);
        $db->query("CREATE INDEX IF NOT EXISTS {$log}_created_at ON {$log} (created_at)", Typecho_Db::WRITE);
    }

    /**
     * 解封一个 IP（同时清零计数）
     * @param string $ip
     * @return boolean 有没有这条记录
     */
    public static function unblockIp($ip)
    {
        $db = Typecho_Db::get();
        $table = $db->getPrefix() . self::TABLE_FAILS;
        $affected = $db->query($db->update($table)->rows(['locked_until' => 0, 'attempts' => 0])->where('ip = ?', $ip));
        return $affected > 0;
    }

    /**
     * 最近的频率记录，封禁中的排在前面
     * @param int $limit
     * @return array 每项 ip, attempts, last_attempt, locked_until；表还没建时为空
     */
    public static function recentFails($limit)
    {
        $db = Typecho_Db::get();
        $table = $db->getPrefix() . self::TABLE_FAILS;
        try {
            $rows = $db->fetchAll($db->select()->from($table)->order($table . '.last_attempt', Typecho_Db::SORT_DESC)->limit((int) $limit));
        } catch (Typecho_Db_Exception $e) {
            return [];
        }
        $now = time();
        usort($rows, function ($a, $b) use ($now) {
            $lockedA = (int) $a['locked_until'] > $now;
            $lockedB = (int) $b['locked_until'] > $now;
            if ($lockedA !== $lockedB) {
                return $lockedA ? -1 : 1;
            }
            return (int) $b['last_attempt'] - (int) $a['last_attempt'];
        });
        return $rows;
    }

    /**
     * 最近的找回密码申请记录
     * @param int $limit
     * @return array 每项 created_at, event, uid, ip, name（用户名，没有时为空）；表还没建时为空
     */
    public static function recentLogs($limit)
    {
        $db = Typecho_Db::get();
        $table = $db->getPrefix() . self::TABLE_LOG;
        try {
            $rows = $db->fetchAll($db->select()->from($table)->order($table . '.created_at', Typecho_Db::SORT_DESC)->limit((int) $limit));
        } catch (Typecho_Db_Exception $e) {
            return [];
        }
        $uids = array_values(array_unique(array_filter(array_map(function ($row) {
            return (int) $row['uid'];
        }, $rows))));
        $names = [];
        if ($uids) {
            foreach ($db->fetchAll($db->select('uid', 'name')->from('table.users')->where('uid IN ?', $uids)) as $user) {
                $names[(int) $user['uid']] = $user['name'];
            }
        }
        foreach ($rows as &$row) {
            $row['name'] = $names[(int) $row['uid']] ?? '';
        }
        return $rows;
    }

    /**
     * 后台配置页里的「立即解封」：表单提交回配置页本身，这里带着 Typecho 的安全令牌核对
     */
    public static function handleUnblockRequest()
    {
        if (empty($_POST['xcaptcha_unblock_ip'])) {
            return;
        }
        $ip = trim((string) $_POST['xcaptcha_unblock_ip']);
        $notice = Typecho_Widget::widget('Widget_Notice');
        if (!Typecho_Widget::widget('Widget_User')->pass('administrator', true)) {
            return;
        }
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            $notice->set(_t('IP 地址无效'), 'error');
            return;
        }
        $request = Typecho_Widget::widget('Widget_Options')->request;
        $expected = Typecho_Widget::widget('Widget_Security')->getToken($request->getRequestUrl());
        if (!is_string($_POST['_'] ?? null) || !hash_equals($expected, $_POST['_'])) {
            $notice->set(_t('安全令牌不匹配，请刷新页面后重试'), 'error');
            return;
        }

        try {
            self::unblockIp($ip);
            $notice->set(_t('已解封 %s', htmlspecialchars($ip, ENT_QUOTES, 'UTF-8')), 'success');
        } catch (Typecho_Db_Exception $e) {
            $notice->set(_t('解封失败：%s', htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8')), 'error');
        }
    }

    /**
     * 后台配置页里的封禁记录表（最近 25 条）
     * @return string HTML，表还没建时为空
     */
    public static function renderFailLog()
    {
        $db = Typecho_Db::get();
        $table = $db->getPrefix() . self::TABLE_FAILS;
        try {
            $logs = $db->fetchAll($db->select()->from($table)->order($table . '.last_attempt', Typecho_Db::SORT_DESC)->limit(25));
        } catch (Typecho_Db_Exception $e) {
            return '';
        }

        $request = Typecho_Widget::widget('Widget_Options')->request;
        $token = Typecho_Widget::widget('Widget_Security')->getToken($request->getRequestUrl());
        $now = time();
        $e = function ($text) {
            return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8');
        };

        $html = '<h2 style="margin-top:2em">' . _t('找回密码：IP 请求记录与封禁状态') . '</h2>'
            . '<table class="typecho-list-table"><colgroup><col width="30%"><col width="10%"><col width="25%"><col width="20%"><col width="15%"></colgroup>'
            . '<thead><tr><th>' . _t('IP') . '</th><th>' . _t('近期次数') . '</th><th>' . _t('最后提交') . '</th><th>' . _t('状态') . '</th><th>' . _t('操作') . '</th></tr></thead><tbody>';
        if (empty($logs)) {
            $html .= '<tr><td colspan="5"><h6 class="typecho-list-table-title">' . _t('没有记录') . '</h6></td></tr>';
        }
        foreach ($logs as $log) {
            $lockedUntil = (int) $log['locked_until'];
            $status = '<span style="color:#4d8f44">' . _t('正常') . '</span>';
            $action = '-';
            if ($lockedUntil > $now) {
                $status = '<span style="color:#d94e4e;font-weight:bold">' . _t('封禁中') . '</span>（' . _t('还剩约 %d 分钟', max(1, (int) ceil(($lockedUntil - $now) / 60))) . '）';
                $action = '<form method="post" style="margin:0">'
                    . '<input type="hidden" name="xcaptcha_unblock_ip" value="' . $e($log['ip']) . '">'
                    . '<input type="hidden" name="_" value="' . $e($token) . '">'
                    . '<button type="submit" class="btn btn-s btn-warn">' . _t('立即解封') . '</button></form>';
            } elseif ($lockedUntil > 0) {
                $status = '<span style="color:#999">' . _t('封禁已过期') . '</span>';
            }
            $html .= '<tr><td>' . $e($log['ip']) . '</td><td>' . (int) $log['attempts'] . '</td>'
                . '<td>' . $e((new Typecho_Date((int) $log['last_attempt']))->format('Y-m-d H:i:s')) . '</td>'
                . '<td>' . $status . '</td><td>' . $action . '</td></tr>';
        }
        $html .= '</tbody></table><p class="description">' . _t('只显示最近 25 条；记录 30 天后自动清理。') . '</p>';
        return $html;
    }
}

/**
 * 找回密码流程里要显示给访客看的错误
 */
class XCaptcha_Passport_Exception extends Exception
{
}
