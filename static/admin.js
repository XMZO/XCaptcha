/* XCaptcha 设置页脚本：标签页、字段联动、无刷新保存和各种检测。没有这个脚本时页面是一整张可提交的表单。 */
(function () {
    'use strict';

    var root = document.getElementById('xc');
    var form = document.getElementById('xc-form');
    if (!root || !form) return;

    var token = root.getAttribute('data-token');
    var actionBase = root.getAttribute('data-action');
    var dirty = false;

    function $(selector, scope) { return (scope || root).querySelector(selector); }
    function $$(selector, scope) { return Array.prototype.slice.call((scope || root).querySelectorAll(selector)); }
    function field(name) { return form.elements[name]; }
    function radioValue(name) {
        var checked = form.querySelector('input[name="' + name + '"]:checked');
        return checked ? checked.value : '';
    }
    function isOn(name) {
        var input = form.querySelector('input[type=checkbox][name="' + name + '"]');
        return !!(input && input.checked);
    }
    function setText(node, text, tone) {
        if (!node) return;
        node.textContent = text;
        node.classList.remove('is-ok', 'is-error');
        if (tone) node.classList.add(tone);
    }

    /* ---------- 标签页 ---------- */

    var tabs = $$('.xc-tabs [role=tab]');
    var panels = $$('[data-tab]');
    var tabIds = tabs.map(function (tab) { return tab.getAttribute('href').slice(1); });

    function storage(method, key, value) {
        try { return window.sessionStorage[method](key, value); } catch (e) { return null; }
    }

    function activate(id, focus) {
        if (tabIds.indexOf(id) < 0) id = tabIds[0];
        tabs.forEach(function (tab, i) {
            var on = tabIds[i] === id;
            tab.setAttribute('aria-selected', on ? 'true' : 'false');
            tab.setAttribute('tabindex', on ? '0' : '-1');
            if (on && focus) tab.focus();
        });
        panels.forEach(function (panel) { panel.hidden = panel.getAttribute('data-tab') !== id; });
        storage('setItem', 'xc-tab', id);
        if (window.history && history.replaceState) history.replaceState(null, '', '#' + id);
    }

    tabs.forEach(function (tab, i) {
        tab.addEventListener('click', function (ev) {
            ev.preventDefault();
            activate(tabIds[i]);
        });
        tab.addEventListener('keydown', function (ev) {
            var step = { ArrowDown: 1, ArrowRight: 1, ArrowUp: -1, ArrowLeft: -1 }[ev.key];
            if (ev.key === 'Home') step = -i;
            if (ev.key === 'End') step = tabIds.length - 1 - i;
            if (step === undefined) return;
            ev.preventDefault();
            activate(tabIds[(i + step + tabIds.length) % tabIds.length], true);
        });
    });

    // 同一页面里改了地址的 #标签（比如点了指向 #logs 的链接）
    window.addEventListener('hashchange', function () {
        var id = (window.location.hash || '').slice(1);
        if (tabIds.indexOf(id) >= 0) activate(id);
    });

    function tabOf(node) {
        var panel = node && node.closest('[data-tab]');
        return panel ? panel.getAttribute('data-tab') : null;
    }

    /* ---------- 字段联动 ---------- */

    var toolLabels = { cap: '站点 key', hcaptcha: 'Site Key', cloudflare: 'Site Key', recaptcha: 'Site Key', geetest: 'Captcha ID' };
    var toolNames = {};
    $$('.xc-tile').forEach(function (tile) {
        toolNames[tile.querySelector('input').value] = tile.querySelector('.xc-tile-name').textContent;
    });

    function showIf(nodes, test) {
        nodes.forEach(function (node) { node.hidden = !test(node); });
    }

    function refresh() {
        var tool = radioValue('captchaChoosen');
        showIf($$('[data-tools]'), function (node) { return node.getAttribute('data-tools').split(' ').indexOf(tool) >= 0; });
        $$('.xc-tile').forEach(function (tile) { tile.classList.toggle('is-checked', tile.querySelector('input').checked); });
        var idLabel = $('[data-label-for-tool]');
        if (idLabel && toolLabels[tool]) idLabel.textContent = toolLabels[tool];
        var cdn = field('cdnUrl');
        var cdnWrap = cdn && cdn.closest('.xc-field');
        if (cdnWrap) cdn.placeholder = tool === 'cap' ? cdnWrap.getAttribute('data-placeholder-cap') : '留空用默认';

        showIf($$('[data-when]'), function (node) { return isOn(node.getAttribute('data-when')); });

        var mode = radioValue('passportCaptcha');
        showIf($$('[data-pmode]'), function (node) { return node.getAttribute('data-pmode') === mode; });
        var hints = { follow: '和「验证码」一样，现在是 ' + (toolNames[tool] || tool), custom: '用另一套工具和密钥', none: '不显示验证码' };
        setText($('[data-pmode-hint]'), hints[mode] || '');

        var ptool = field('passportCaptchaChoosen') ? field('passportCaptchaChoosen').value : '';
        showIf($$('[data-ptools]'), function (node) { return node.getAttribute('data-ptools').split(' ').indexOf(ptool) >= 0; });
    }

    /* ---------- 未保存提示、字段错误 ---------- */

    function markDirty() {
        if (dirty) return;
        dirty = true;
        $$('.xc-saved').forEach(function (node) { setText(node, '有未保存的修改'); });
    }

    function clearError(name) {
        var slot = form.querySelector('[data-error-for="' + name + '"]');
        if (slot) { slot.hidden = true; slot.textContent = ''; }
        var input = field(name);
        if (input && input.classList) input.classList.remove('is-invalid');
    }

    function showErrors(errors) {
        var first = null;
        Object.keys(errors || {}).forEach(function (name) {
            var slot = form.querySelector('[data-error-for="' + name + '"]');
            var input = field(name);
            if (slot) { slot.textContent = errors[name]; slot.hidden = false; }
            if (input && input.classList) {
                input.classList.add('is-invalid');
                input.setAttribute('aria-invalid', 'true');
            }
            if (!first) first = (input && input.nodeType) ? input : slot;
        });
        if (first) {
            activate(tabOf(first));
            if (first.focus) first.focus();
        }
    }

    form.addEventListener('input', function (ev) {
        if (ev.target.name) clearError(ev.target.name);
        markDirty();
    });
    form.addEventListener('change', function (ev) {
        if (ev.target.name) clearError(ev.target.name);
        markDirty();
        refresh();
    });
    window.addEventListener('beforeunload', function (ev) {
        if (!dirty) return;
        ev.preventDefault();
        ev.returnValue = '';
    });

    /* ---------- 请求 ---------- */

    function post(action, body) {
        body.set('_', token);
        return fetch(actionBase + '?do=' + action, {
            method: 'POST',
            body: body,
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
        }).then(function (res) {
            return res.json().catch(function () { return { ok: false, message: '服务器返回的不是 JSON（HTTP ' + res.status + '）' }; });
        }, function () {
            return { ok: false, message: '网络错误，没有连上服务器' };
        });
    }

    function busy(buttons, on) {
        buttons.forEach(function (button) { button.disabled = on; });
    }

    /* ---------- 保存 ---------- */

    form.addEventListener('submit', function (ev) {
        ev.preventDefault();
        var buttons = $$('button[type=submit]', form);
        busy(buttons, true);
        $$('.xc-saved').forEach(function (node) { setText(node, '正在保存…'); });
        post('save', new FormData(form)).then(function (data) {
            busy(buttons, false);
            if (data.ok) {
                dirty = false;
                storage('setItem', 'xc-flash', JSON.stringify({ message: data.message, notes: data.notes || [] }));
                window.location.reload();
                return;
            }
            $$('.xc-saved').forEach(function (node) { setText(node, data.message || '保存失败', 'is-error'); });
            showErrors(data.errors);
        });
    });

    function showFlash() {
        var raw = storage('getItem', 'xc-flash');
        if (!raw) return;
        storage('removeItem', 'xc-flash');
        var flash;
        try { flash = JSON.parse(raw); } catch (e) { return; }
        var text = flash.message + (flash.notes && flash.notes.length ? '；' + flash.notes.join('；') : '');
        $$('.xc-saved').forEach(function (node) { setText(node, text, flash.notes && flash.notes.length ? 'is-error' : 'is-ok'); });
    }

    /* ---------- 检测 Cap ---------- */

    $$('[data-test-cap]').forEach(function (button) {
        button.addEventListener('click', function () {
            var passport = button.getAttribute('data-test-cap') === 'passport';
            var prefix = passport ? 'passport' : '';
            var name = function (n) { return prefix ? prefix + n.charAt(0).toUpperCase() + n.slice(1) : n; };
            var body = new URLSearchParams();
            body.set('profile', passport ? 'passport' : 'main');
            body.set('endpoint', field(name('capEndpoint')).value);
            body.set('siteKey', field(name('captchaId')).value);
            body.set('secret', field(name('secretKey')).value);
            body.set('cdnUrl', field(name('cdnUrl')).value);
            body.set('verifyUrl', field(name('verifyUrl')).value);
            var out = $('[data-result="cap-' + (passport ? 'passport' : 'main') + '"]');
            out.textContent = '';
            var waiting = document.createElement('span');
            waiting.className = 'xc-tag';
            waiting.textContent = '检测中…';
            out.appendChild(waiting);
            busy([button], true);
            post('testCap', body).then(function (data) {
                busy([button], false);
                out.textContent = '';
                var items = data.checks || [{ ok: false, label: data.message || '检测失败', detail: '' }];
                items.forEach(function (check) {
                    var tag = document.createElement('span');
                    tag.className = 'xc-tag ' + (check.ok ? 'is-ok' : 'is-danger');
                    tag.textContent = (check.ok ? '✓ ' : '✗ ') + check.label + (check.detail ? ' · ' + check.detail : '');
                    out.appendChild(tag);
                });
            });
        });
    });

    /* ---------- 测试邮件 ---------- */

    var mailButton = $('[data-test-mail]');
    if (mailButton) {
        mailButton.addEventListener('click', function () {
            var body = new URLSearchParams();
            ['passportSmtpHost', 'passportSmtpPort', 'passportSmtpSecure', 'passportSmtpUser', 'passportSmtpPassword', 'passportMailFrom', 'passportMailFromName'].forEach(function (n) {
                body.set(n, field(n).value);
            });
            var out = mailButton.closest('.xc-foot').querySelector('.xc-saved');
            setText(out, '正在发送…');
            busy([mailButton], true);
            post('testMail', body).then(function (data) {
                busy([mailButton], false);
                setText(out, data.message || '发送失败', data.ok ? 'is-ok' : 'is-error');
                if (data.errors) showErrors(data.errors);
            });
        });
    }

    /* ---------- 解封 ---------- */

    $$('[data-unblock]').forEach(function (button) {
        button.addEventListener('click', function (ev) {
            ev.preventDefault();
            var body = new URLSearchParams();
            body.set('ip', button.getAttribute('data-unblock'));
            busy([button], true);
            post('unblock', body).then(function (data) {
                var item = button.closest('li');
                var status = item && item.querySelector('[data-status]');
                if (data.ok) {
                    if (status) { status.className = 'xc-tag is-muted'; status.textContent = '已解封'; }
                    button.remove();
                } else {
                    busy([button], false);
                    if (status) { status.className = 'xc-tag is-danger'; status.textContent = data.message || '解封失败'; }
                }
            });
        });
    });

    /* ---------- 复制链接、占位符、恢复默认模板 ---------- */

    $$('[data-copy]').forEach(function (button) {
        button.addEventListener('click', function () {
            var text = button.getAttribute('data-copy');
            var label = button.textContent;
            var done = function () {
                button.textContent = '已复制';
                setTimeout(function () { button.textContent = label; }, 1500);
            };
            if (navigator.clipboard && window.isSecureContext) {
                navigator.clipboard.writeText(text).then(done, function () { window.prompt('复制这个地址', text); });
            } else {
                window.prompt('复制这个地址', text);
            }
        });
    });

    var template = field('passportMailTemplate');
    $$('[data-insert]').forEach(function (button) {
        button.addEventListener('click', function () {
            var text = button.getAttribute('data-insert');
            var start = template.selectionStart || 0;
            var end = template.selectionEnd || 0;
            template.value = template.value.slice(0, start) + text + template.value.slice(end);
            template.focus();
            template.selectionStart = template.selectionEnd = start + text.length;
            markDirty();
        });
    });
    var resetTemplate = $('[data-reset-template]');
    if (resetTemplate) {
        resetTemplate.addEventListener('click', function () {
            template.value = template.getAttribute('data-default');
            markDirty();
        });
    }

    /* ---------- 初始状态 ---------- */

    refresh();
    var hash = (window.location.hash || '').slice(1);
    activate(tabIds.indexOf(hash) >= 0 ? hash : (storage('getItem', 'xc-tab') || tabIds[0]));
    showFlash();
})();
