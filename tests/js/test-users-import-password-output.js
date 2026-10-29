'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const test = require('node:test');
const { execFileSync } = require('node:child_process');

const source = fs.readFileSync(require.resolve('../../assets/js/mainwp-users.js'), 'utf8');
const decode = value => String(value).replace(/&(amp|lt|gt|quot|#039);/g, (_, entity) => ({ amp: '&', lt: '<', gt: '>', quot: '"', '#039': "'" }[entity]));
const encode = value => String(value).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
const csv = fields => fields.map(value => /[,"\r\n]/.test(String(value)) ? '"' + String(value).replace(/"/g, '""') + '"' : value).join(',');

function harness(rows, replies = []) {
    const elements = new Map();
    const handlers = new Map();
    let saved;
    const posted = [];
    const make = () => ({ content: '', attributes: {}, length: 1, children: [],
        append(value) { if (typeof value === 'object') { this.children.push(value); this.content += value.content; } else this.content += value; return this; },
        text(value) { if (value === undefined) return this.content; this.content = String(value); return this; },
        html(value) { if (value === undefined) return encode(this.content); this.content = decode(value); return this; },
        val(value) { if (value === undefined) return this.value; this.value = value; return this; },
        attr(name, value) { if (value === undefined) return this.attributes[name]; this.attributes[name] = value; return this; },
        on(event, callback) { handlers.set(this, callback); return this; },
        each(callback) { for (const child of elements.get('#import_user_import_failed_rows').children) callback.call(child); return this; },
        show() { this.visible = true; return this; }, hide() { this.visible = false; return this; },
        scrollTop() { return this; }, height() { return 0; }
    });
    const jq = selector => {
        if (typeof selector === 'function') { selector(); return; }
        if (typeof selector === 'object') return selector;
        if (selector.startsWith('<')) return make();
        if (!elements.has(selector)) elements.set(selector, make());
        return elements.get(selector);
    };
    jq.inArray = (value, list) => list.indexOf(value);
    jq('#import_user_total_import').value = rows.length;
    jq('#import_user_do_import').value = 0;
    for (const [index, row] of rows.entries()) {
        const fields = [row.user_login, row.email, row.first_name, row.last_name, row.url, '', row.send_password, row.role, row.select_sites, row.select_groups];
        jq('#user_import_csv_line_' + (index + 1)).attributes = { 'encoded-data': JSON.stringify(row), 'original-line': csv(fields) };
    }
    jq.post = (url, data, callback) => {
        posted.push(data);
        const reply = replies.shift();
        if (reply !== 'transport') callback(reply);
        return { fail(handler) { if (reply === 'transport') handler({ responseText: 'PRIVATE_REQUEST_PASSWORD' }); return this; } };
    };
    const context = vm.createContext({ jQuery: jq, MainWP: { I18n: { t: text => text } }, _: text => text,
        mainwp_secure_data: data => data, ajaxurl: '/ajax', Blob, saveAs: blob => { saved = blob; }, console });
    vm.runInContext(source.slice(source.indexOf('let import_user_stop_by_user'), source.indexOf('jQuery(function ()')), context);
    vm.runInContext(source.slice(source.indexOf('/**\n * Bulk upload new user')), context);
    return { run: code => vm.runInContext(code, context), jq, elements,
        click: selector => handlers.get(jq(selector))(), saved: () => saved, posted };
}

const row = overrides => ({ user_login: 'csvuser', email: 'csv@example.test', first_name: 'Name, "quoted" &lt;', last_name: '<b>Text</b>', url: '', pass1: 'PRIVATE_REQUEST_PASSWORD', send_password: 1, role: 'subscriber', select_sites: 'https://good.test;https://failed.test', select_groups: '', ...overrides });

test('failed response keeps only failed targets, decodes once, and downloads text', async () => {
    const h = harness([row()]);
    const safe = csv(['csvuser', 'csv@example.test', 'Name, "quoted" &lt;', '<b>Text</b>', '', '', 1, 'subscriber', 'https://failed.test', '']);
    h.run('mainwp_import_users_response(' + JSON.stringify({ line_number: 1, ok_list: ['Created'], error_list: ['Failed'], failed_logging: encode(safe) }) + ')');
    const failed = h.jq('#import_user_import_failed_rows');
    assert.equal(failed.children.length, 1, 'failed rows must be inserted as text nodes');
    assert.equal(failed.children[0].text(), safe);
    h.click('#import_user_btn_save_csv');
    assert.equal(await h.saved().text(), safe + '\r\n');
    assert.doesNotMatch(await h.saved().text(), /PRIVATE_REQUEST_PASSWORD|https:\/\/good.test/);
});

for (const failure of [{ error: 'PRIVATE_REQUEST_PASSWORD' }, 'transport']) {
    test('recovers a safe failed row and continues after ' + (typeof failure === 'string' ? failure : 'top-level error'), () => {
        const h = harness([row(), row({ user_login: 'nextuser' })], [failure, { line_number: 2, ok_list: ['Created'], error_list: [], failed_logging: '' }]);
        h.run('mainwp_import_users_next()');
        assert.equal(h.jq('#import_user_import_failed_rows').children.length, 1);
        assert.equal(h.run('import_user_count_created_users'), 1);
        assert.equal(h.run('import_user_count_create_fails'), 1);
        assert.equal(h.jq('#import_user_failed_password_note').visible, true);
        assert.doesNotMatch(h.jq('#import_user_import_logging .log').content, /PRIVATE_REQUEST_PASSWORD/);
    });
}

test('validation and malformed JSON preserve safe rows; pause and resume do not duplicate them', async () => {
    const h = harness([row({ pass1: '' }), row()]);
    h.jq('#user_import_csv_line_2').attributes['encoded-data'] = 'bad json';
    h.run('import_user_stop_by_user = true; mainwp_import_users_next()');
    assert.equal(h.run('import_user_current_line_number'), 0);
    h.click('#import_user_btn_import');
    assert.equal(h.jq('#import_user_import_failed_rows').children.length, 2);
    h.click('#import_user_btn_save_csv');
    assert.doesNotMatch(await h.saved().text(), /PRIVATE_REQUEST_PASSWORD/);
    assert.match(h.jq('#import_user_import_logging .log').content, /Please enter a password/);
});

function importUsers(args, partial) {
    const php = `<?php
namespace MainWP\\Dashboard {
    class MainWP_System_Utility {
        public static function get_default_map_site_fields() { return array(); }
        public static function is_suspended_site($site) { return false; }
        public static function can_edit_website($site) { return true; }
    }
    class MainWP_DB {
        public static function instance() { return new self(); }
        public function get_websites_by_url($url) {
            if (!${partial ? 'true' : 'false'}) return false;
            return array((object) array('id' => strpos($url, 'good') !== false ? 1 : 2, 'name' => $url, 'url' => $url, 'sync_errors' => ''));
        }
    }
    class MainWP_Utility { public static function map_site($site, $fields) { return $site; } }
    class MainWP_Bulk_Add { public static function get_class_name() { return __CLASS__; } }
    class MainWP_Connect {
        public static function fetch_urls_authed($sites, $action, $data, $callback, $output) {
            $received = json_decode(base64_decode($data['new_user']), true);
            foreach (array('user_pass' => 'pass1', 'first_name' => 'first_name', 'last_name' => 'last_name') as $field => $request_field) {
                $expected = $GLOBALS['test_request'][$request_field];
                if ('user_pass' !== $field) $expected = sanitize_text_field($expected);
                if ($received[$field] !== $expected) throw new \RuntimeException('Account-creation input was changed.');
            }
            $output->ok[1] = 1;
            $output->errors[2] = 'Duplicate user';
        }
    }
}
namespace {
    define('ABSPATH', '/test/');
    function wp_unslash($value) {
        return is_array($value) ? array_map('wp_unslash', $value) : stripslashes($value);
    }
    function sanitize_text_field($value) { return strip_tags($value); }
    function esc_html($value) { return htmlspecialchars($value, ENT_QUOTES, 'UTF-8', false); }
    function esc_html__($value, $domain) { return esc_html($value); }
    function wp_json_encode($value) { return json_encode($value); }
    function do_action(...$args) {}
    function mainwp_current_user_can($type, $id) { return true; }
    function wp_slash($value) {
        return is_array($value) ? array_map('wp_slash', $value) : (is_string($value) ? addslashes($value) : $value);
    }
    $GLOBALS['test_request'] = json_decode(stream_get_contents(STDIN), true);
    $_POST = wp_slash($GLOBALS['test_request']);
    require ${JSON.stringify(process.env.MAINWP_USERS_PHP || require.resolve('../../pages/page-mainwp-user.php'))};
    \\MainWP\\Dashboard\\MainWP_User::do_import();
}`;
    const output = execFileSync('php', ['-r', php.slice(6)], { input: JSON.stringify(args), encoding: 'utf8' });
    return { output, result: JSON.parse(output) };
}

for (const partial of [false, true]) {
    test('production PHP import returns a blank password field after ' + (partial ? 'partial failure' : 'early site failure'), () => {
        const args = row({ select_by: 'site', select_sites: ['https://good.test', 'https://failed.test'] });
        const { output, result } = importUsers(args, partial);
        assert.doesNotMatch(output, /PRIVATE_REQUEST_PASSWORD/);
        const safe = decode(result.failed_logging);
        const targets = partial ? 'https://failed.test' : 'https://good.test;https://failed.test';
        assert.equal(safe, csv(['csvuser', 'csv@example.test', 'Name, "quoted" &lt;', 'Text', '', '', 1, 'subscriber', targets, '']));
        assert.equal(result.ok_list.length, partial ? 1 : 0);
    });
}

function uploadUsers(contents) {
    const php = `<?php
namespace MainWP\\Dashboard {
    function is_uploaded_file($path) { return true; }
    class MainWP_System_Utility { public static function get_wp_file_system() { return true; } }
    class MainWP_Utility { public static function starts_with($value, $prefix) { return strpos($value, $prefix) === 0; } }
}
namespace {
    define('ABSPATH', '/test/');
    function wp_unslash($value) {
        return is_array($value) ? array_map('wp_unslash', $value) : stripslashes($value);
    }
    function sanitize_text_field($value) { return strip_tags($value); }
    function esc_html($value) { return htmlspecialchars($value, ENT_QUOTES, 'UTF-8', false); }
    function esc_attr($value) { return esc_html($value); }
    function esc_html__($value, $domain) { return esc_html($value); }
    function esc_url($value) { return esc_html($value); }
    function get_admin_url() { return '/test/'; }
    function esc_html_e($value, $domain) { echo esc_html($value); }
    function esc_attr_e($value, $domain) { echo esc_attr($value); }
    function wp_json_encode($value) { return json_encode($value); }
    function do_action(...$args) {}
    function sanitize_key($value) { return strtolower(preg_replace('/[^a-zA-Z0-9_-]/', '', $value)); }
    function wp_verify_nonce($nonce, $action) { return 'fixture-nonce' === $nonce && 'mainwp-admin-nonce' === $action; }
    $csv_contents = stream_get_contents(STDIN);
    $wp_filesystem = new class {
        public function get_contents($path) { return $GLOBALS['csv_contents']; }
    };
    $_FILES = array('import_user_file_bulkupload' => array('error' => UPLOAD_ERR_OK, 'tmp_name' => '/test/upload.csv'));
    $_POST = array('_wpnonce' => 'fixture-nonce');
    require ${JSON.stringify(process.env.MAINWP_USERS_PHP || require.resolve('../../pages/page-mainwp-user.php'))};
    class Upload_Render_Test extends \\MainWP\\Dashboard\\MainWP_User {
        public static function render_header($shownPage = '') {}
        public static function render_footer() {}
    }
    Upload_Render_Test::render_bulk_upload();
}`;
    const output = execFileSync('php', ['-r', php.slice(6)], { input: contents, encoding: 'utf8' });
    const input = output.match(/<input\b[^>]*id="user_import_csv_line_1"[^>]*>/);
    assert.ok(input, 'production upload must render an import row');
    return {
        operational: JSON.parse(decode(input[0].match(/encoded-data="([^"]*)"/)[1])),
        display: decode(input[0].match(/original-line="([^"]*)"/)[1])
    };
}

test('production upload rendering preserves literal entities in operational input and omits the display password', () => {
    const name = 'CSV, "Quote" &amp; &lt;';
    const password = 'PRIVATE_REQUEST_PASSWORD&amp;,"Quote"';
    const fields = ['csvuser', 'csv@example.test', name, 'Last &quot;', '', password, 1, 'subscriber', 'https://failed.test', ''];
    const { operational, display } = uploadUsers(csv(fields));
    assert.equal(operational.first_name, name, 'literal entities must survive the DOM attribute before AJAX');
    assert.equal(operational.last_name, fields[3]);
    assert.equal(operational.pass1, password, 'operational password must retain its intended value');
    fields[5] = '';
    assert.equal(display, csv(fields));
    assert.doesNotMatch(display, /PRIVATE_REQUEST_PASSWORD/);
});

const roundTripCases = [
    { label: 'backslash before a quote', firstName: 'Name\\"quoted', lastName: 'Last\\\\Name' },
    { label: 'comma and trailing backslash', firstName: 'Name, trailing\\', lastName: 'Last\\' },
    { label: 'literal entities and quotes', firstName: 'Name, "quoted" &amp; &lt;', lastName: 'Last &quot; \\\\Name' }
];

function fieldsFor(data) {
    return [data.user_login, data.email, data.first_name, data.last_name, data.url, data.pass1,
        data.send_password, data.role, data.select_sites, data.select_groups];
}

for (const sample of roundTripCases) {
    for (const failure of ['validation', 'transport', 'server']) {
        test('CSV round trip preserves ' + sample.label + ' after ' + failure + ' failure', async () => {
            const original = row({
                first_name: sample.firstName,
                last_name: sample.lastName,
                pass1: 'PRIVATE_REQUEST_PASSWORD\\",tail&amp;',
                role: failure === 'validation' ? 'invalid-role' : 'subscriber'
            });
            const uploaded = uploadUsers(csv(fieldsFor(original)));
            assert.deepEqual(uploaded.operational, original, 'raw CSV cells must not be unslashed');

            let reply = 'transport';
            if (failure === 'server') {
                const { output, result } = importUsers({
                    ...uploaded.operational,
                    select_by: 'site',
                    select_sites: original.select_sites.split(';'),
                    select_groups: []
                }, true);
                assert.doesNotMatch(output, /PRIVATE_REQUEST_PASSWORD/);
                reply = result;
            }

            const h = harness([uploaded.operational], [reply]);
            h.jq('#user_import_csv_line_1').attr('original-line', uploaded.display);
            h.run('mainwp_import_users_next()');
            assert.equal(h.posted.length, failure === 'validation' ? 0 : 1);
            if (h.posted.length) assert.equal(h.posted[0].pass1, original.pass1);
            assert.equal(h.run('import_user_count_create_fails'), 1);
            assert.equal(h.run('import_user_count_created_users'), failure === 'server' ? 1 : 0);
            assert.equal(h.jq('#import_user_failed_password_note').visible, true);
            assert.doesNotMatch(h.jq('#import_user_import_logging .log').content, /PRIVATE_REQUEST_PASSWORD/);

            h.click('#import_user_btn_save_csv');
            const downloaded = await h.saved().text();
            const expected = {
                ...original,
                pass1: '',
                select_sites: failure === 'server' ? 'https://failed.test' : original.select_sites
            };
            assert.equal(downloaded, csv(fieldsFor(expected)) + '\r\n', 'download must keep all ten columns with an empty password');
            assert.doesNotMatch(downloaded, /PRIVATE_REQUEST_PASSWORD/);
            const recovered = uploadUsers(downloaded);
            assert.deepEqual(recovered.operational, expected, 'downloaded data must survive re-upload exactly');

            const refilled = {
                ...recovered.operational,
                pass1: 'REFILLED_TEST_PASSWORD\\",tail&amp;',
                role: 'subscriber'
            };
            const retry = uploadUsers(csv(fieldsFor(refilled)));
            assert.deepEqual(retry.operational, refilled, 'refilling the password must preserve the remaining fields');
            const { output, result } = importUsers({
                ...retry.operational,
                select_by: 'site',
                select_sites: retry.operational.select_sites.split(';'),
                select_groups: []
            }, true);
            assert.equal(result.error_list.length, 1);
            assert.doesNotMatch(output, /PRIVATE_REQUEST_PASSWORD|REFILLED_TEST_PASSWORD/);
        });
    }
}
