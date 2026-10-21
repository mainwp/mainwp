'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const nodeTest = require('node:test');
const scenarios = new Map();
function test(name, run) {
    const group = name.split(': ')[0];
    if (!scenarios.has(group)) scenarios.set(group, []);
    scenarios.get(group).push({ name, run });
}
const { document, decodeEntities, escapeHtml, loadSiteNameHelper } = require('./fixtures/site-name-dom');
// nosemgrep: AIK_ts_generic_path_traversal -- fixed asset file names under the repository, not input
const sources = Object.fromEntries(['mainwp', 'mainwp-managesites-action', 'mainwp-uptime'].map(name => [name, fs.readFileSync(require.resolve('../../assets/js/' + name + '.js'), 'utf8')]));
const cases = ['<img src=x onerror=alert(1)>', '&lt;img src=x onerror=alert(1)&gt;', 'A & B', 'A &amp; B', '\"><img src=x onerror=alert(1)>', '100% uptime', '&amp;lt;img src=x onerror=alert(1)&amp;gt;'];
const escaped = value => escapeHtml(decodeEntities(value));

function load(h, name, file = 'mainwp') {
    const source = sources[file];
    const start = source.search(new RegExp('(?:globalThis\\.|let |const )' + name + ' = function'));
    assert.ok(start >= 0, name);
    const end = source.indexOf('\n}', start);
    vm.runInContext(source.slice(start, end + 2) + ';\nglobalThis.' + name + ' = ' + name + ';', h.context);
}

function harness(raw = 'Site') {
    const writes = [], confirms = [], events = new Map(), timers = [];
    const nodes = new Map();
    let respond;
    const attrs = { 'site-name': raw, 'site-url': raw, siteid: '7', 'site-id': '7', 'plugin-title': raw, 'item-name': raw, 'item-slug': raw, 'item-type': raw, 'cost-id': raw, 'item-siteid': '7' };
    function node(selector) {
        if (nodes.has(selector)) return nodes.get(selector);
        let text = '', html = '';
        const classes = new Set(['child-site']);
        const n = {
            length: selector === '.siteBulkInstall[status="queue"]:first' ? 0 : 1,
            attr(key, value) { if (typeof key === 'object') { Object.assign(attrs, key); writes.push({ selector, method: 'attr', value: key }); return this; } if (arguments.length === 2) { attrs[key] = value; return this; } return attrs[key]; },
            val() { return selector === '.themeName' ? raw : selector === 'input[name="select_by"]' ? 'site' : 'value'; },
            each(fn) { fn.call(row, 0, row); return this; },
            closest() { return row; }, parent() { return this; }, children(key) { return node(key); }, next() { return this; },
            find(key) { return node(key); }, hide() { return this; }, show() { return this; },
            html(value) { if (!arguments.length) return html || escapeHtml(text); html = value; writes.push({ selector, method: 'html', value }); return this; },
            text(value) { if (!arguments.length) return text; text = value; writes.push({ selector, method: 'text', value }); return this; },
            append(value) { writes.push({ selector, method: 'append', value }); return this; },
            hasClass(key) { return classes.has(key) || key === 'qq-upload-success'; },
            addClass(key) { classes.add(key); return this; }, removeClass(key) { classes.delete(key); return this; },
            classes, css() { return this; }, progress() { return this; }, modal() { return this; }, is() { return false; },
            remove() { return this; }, after(value) { writes.push({ selector, method: 'after', value }); return this; },
            fadeOut() { writes.push({ selector, method: 'fadeOut' }); return this; },
            add() { return this; }, empty() { return this; }, contents() { return { length: 0 }; },
            on(event, selector, fn) { events.set(selector, fn); return this; }
        };
        nodes.set(selector, n);
        return n;
    }
    const row = node('row');
    const jq = selector => {
        if (typeof selector === 'string') assert.ok(!selector.startsWith('.mainwp_cost_tracker_assistant_installed_items[item-slug='), 'Do not interpolate a Child slug into CSS');
        if (typeof selector === 'function') { selector(jq); return; }
        return typeof selector === 'object' && !selector.createElement ? selector : node(selector);
    };
    jq.post = (url, data, callback) => { respond = callback; };
    const context = {
        document: { ...document, createElement: tag => {
            if (tag !== 'textarea') return document.createElement(tag);
            let text = '';
            return { set innerHTML(value) { text = decodeEntities(String(value).replace(/&not(?==)/g, '¬')); }, get value() { return text; } };
        }, createTextNode: value => ({ textContent: value }) }, jQuery: jq, ajaxurl: '/ajax',
        MainWP: { I18n: { t: (text, ...args) => text.replace(/%(\d)/g, (_, i) => args[i - 1]) } },
        mainwpVars: {}, bulkInstallDone: 0, bulkInstallCurrentThreads: 0, bulkInstallMaxThreads: 3,
        bulkManageSitesCurrentThreads: 0, bulkManageSitesFinished: 0,
        mainwp_secure_data: data => data, managesites_init() {},
        mainwp_confirm: message => confirms.push(message),
        mainwp_install_bulk_start_next() {}, mainwp_upload_bulk_start_next() {}, mainwp_managesites_bulk_reconnect_next() {}, mainwp_managesites_bulk_remove_next() {},
        mainwp_links_visit_site_and_admin: () => '', mainwp_install_bulk_you_know_msg: () => '',
        mainwp_js_get_error_not_detected_connect: () => false,
        mainwp_feedback_linked_message: () => node('reconnect-nodes').text('Reconnect failed. Please try again from the Site Settings page.'),
        mainwp_forceReload() {}, setTimeout: fn => { timers.push(fn); },
        feedback: (id, value) => { if (typeof value === 'string') node('#' + id).text(value); else node('#' + id).append(value); },
        mainwp_set_message_zone: (id, value) => { if (value !== undefined) node(id).html(value); }
    };
    const h = { context, writes, confirms, events, timers, row, node, respond: value => { assert.ok(respond, 'request reached'); respond(value); } };
    vm.createContext(context);
    loadSiteNameHelper(vm, sources.mainwp, context);
    for (const name of ['mainwp_get_reconnect_error', 'mainwp_reconnect_error_nodes']) {
        if (sources.mainwp.includes('globalThis.' + name + ' =')) load(h, name);
    }
    return h;
}

function htmlWrites(h) { return h.writes.filter(w => typeof w.value === 'string' && ['html', 'append', 'after'].includes(w.method)).map(w => w.value); }
function assertSafe(value) { assert.doesNotMatch(value, /<img\b|["']\s+onerror\s*=/i); }

for (const raw of cases) {
    for (const group of ['remove', 'plugin', 'theme']) {
        test('confirm dialogs ' + group + ': ' + raw, () => {
            const h = harness(raw);
            if (group === 'remove') { load(h, 'managesites_remove'); h.context.managesites_remove(h.row); }
            else {
                const source = sources.mainwp;
                const start = source.lastIndexOf('jQuery(function ()', source.indexOf("'.mainwp-" + group + "-delete'"));
                const end = source.indexOf('\n});', start) + 4;
                vm.runInContext(source.slice(start, end), h.context);
                h.events.get('.mainwp-' + group + '-delete').call(h.row);
            }
            assert.equal(h.confirms.length, 1);
            assert.ok(h.confirms[0].includes(escaped(raw)), h.confirms[0]);
            assertSafe(h.confirms[0]);
        });
    }
    for (const name of ['mainwp_install_bulk', 'mainwp_upload_bulk']) {
        for (const field of ['name', 'url']) {
            test('bulk queues ' + name + ' ' + field + ': ' + raw, () => {
                const h = harness(raw); load(h, name); h.context[name]('plugins', 'fixture', 'Fixture');
                const url = field === 'url' ? 'https://child.example/" data-value="' + raw : 'https://child.example/';
                h.respond({ sites: { 7: { name: raw, url } } });
                const html = htmlWrites(h).find(value => value.includes('siteBulkInstall'));
                assert.ok(html.includes('>' + escaped(raw) + '</a>'), html);
                assert.ok(html.includes('href="' + escapeHtml(url) + '"'), html);
                assertSafe(html);
            });
        }
    }
    test('bulk results Child key: ' + raw, () => {
        const h = harness(raw); load(h, 'mainwp_upload_bulk_start_specific');
        h.context.mainwp_upload_bulk_start_specific('plugin', [], false, false, h.row);
        h.respond({ ok: { 7: true }, results: { 7: { [raw]: true } } });
        const html = htmlWrites(h).find(value => value.includes('right floated content'));
        assert.ok(html.includes('class="content">' + escaped(raw) + '</div>'), html); assertSafe(html);
    });
    for (const field of ['name', 'slug', 'type', 'cost_id', 'sites_ids', 'edit_name']) {
        test('bulk Cost Tracker ' + field + ': ' + raw, () => {
            const h = harness('Fixture');
            h.row.attr('item-' + field, raw);
            if (field === 'cost_id') h.row.attr('cost-id', raw);
            if (field === 'sites_ids') h.row.attr('item-siteid', raw);
            if (field === 'edit_name') { h.row.attr('item-name', raw); h.row.attr('cost-id', '9'); }
            load(h, 'mainwp_upload_bulk_start_next'); h.context.mainwp_upload_bulk_start_next('plugin', [], false, false);
            const html = htmlWrites(h).find(value => value.includes('multi-add-to='));
            const attr = field === 'cost_id' ? 'cost-id' : field === 'sites_ids' ? 'installed-sites' : field === 'edit_name' ? 'item-name' : 'item-' + field;
            assert.ok(html.includes(attr + '="' + escapeHtml(raw) + '"'), html);
            if (field === 'name' || field === 'edit_name') assert.ok(html.includes('(' + escapeHtml(raw) + ')'), html);
            assertSafe(html);
        });
    }
    for (const name of ['mainwp_install_bulk_start_specific', 'mainwp_upload_bulk_start_specific']) {
        test('bulk error tooltip ' + name + ': ' + raw, () => {
            const h = harness(raw); load(h, name);
            h.context[name]('plugin', [], false, false, h.row, 'fixture', {});
            h.respond({ ok: {}, errors: { 7: [false, raw] } });
            const html = htmlWrites(h).find(value => value.includes('data-tooltip=') && value.includes('times red icon'));
            assert.ok(html.includes('data-tooltip="' + escaped(raw) + '"'), html); assertSafe(html);
        });
    }
    for (const name of ['postAction', 'plugin_theme_doAction']) {
        test('widget responses ' + name + ': ' + raw, () => {
            const h = harness(raw); load(h, name);
            if (name === 'postAction') h.context[name](h.row, 'delete'); else h.context[name]({}, h.row);
            h.respond({ error: raw });
            const html = htmlWrites(h).at(-1);
            assert.ok(html.includes(escaped(raw)), html); assertSafe(html);
        });
    }
    for (const field of ['host', 'CNAME', 'error', 'httpCodeString', 'ip', 'resolved_ip', 'httpCode']) {
        test('test-connection result ' + field + ': ' + raw, () => {
            const h = harness(raw); load(h, 'mainwp_render_test_connection_result');
            h.context.mainwp_render_test_connection_result({ host: 'child.example', httpCode: '500', [field === 'CNAME' ? 'host' : field]: field === 'CNAME' ? 'child.example (CNAME: ' + raw + ')' : raw });
            const html = htmlWrites(h).at(-1);
            assert.ok(html.includes(escaped(raw)), html); assertSafe(html);
        });
    }
    for (const field of ['host', 'CNAME', 'ip', 'httpCodeString']) {
        test('test-connection success ' + field + ': ' + raw, () => {
            const h = harness(raw); load(h, 'mainwp_render_test_connection_result');
            h.context.mainwp_render_test_connection_result({ host: 'child.example', httpCode: '200', [field === 'CNAME' ? 'host' : field]: field === 'CNAME' ? 'child.example (CNAME: ' + raw + ')' : raw });
            const html = htmlWrites(h).at(-1);
            assert.ok(html.includes(escaped(raw)), html); assertSafe(html);
        });
    }
    test('test-connection no-code error: ' + raw, () => {
        const h = harness(raw); load(h, 'mainwp_render_test_connection_result');
        h.context.mainwp_render_test_connection_result({ error: raw });
        const html = htmlWrites(h).at(-1);
        assert.ok(html.includes(escaped(raw)), html); assertSafe(html);
    });
    test('reconnect rendering successful site-url: ' + raw, () => {
        const h = harness(raw); load(h, 'mainwp_managesites_bulk_reconnect_specific', 'mainwp-managesites-action');
        h.row.addClass('error'); h.context.mainwp_managesites_bulk_reconnect_specific(h.row); h.row.removeClass('error'); h.respond('Site reconnected!');
        const html = htmlWrites(h).at(-1);
        assert.ok(html.includes(escapeHtml(raw)), html); assertSafe(html);
    });
    for (const field of ['niceurl', 'url', 'id', 'siteid']) {
        test('uptime rows ' + field + ': ' + raw, () => {
            const h = harness(raw); load(h, 'uptime_monitoring_prepare_items_rows', 'mainwp-uptime');
            h.context.uptime_monitoring_prepare_items_rows([{ id: '7', siteid: '7', niceurl: 'Site', url: 'https://child.example/', [field]: raw }]);
            const html = htmlWrites(h).at(-1);
            assert.ok(html.includes(escapeHtml(raw)), html); assertSafe(html);
        });
    }
    test('reconnect rendering bulk site-url: ' + raw, () => {
        const h = harness(raw); load(h, 'mainwp_managesites_bulk_reconnect_specific', 'mainwp-managesites-action');
        h.row.addClass('error'); h.context.mainwp_managesites_bulk_reconnect_specific(h.row); h.row.removeClass('error'); h.respond('ERROR');
        const html = htmlWrites(h).at(-1);
        assert.ok(h.writes.some(w => w.method === 'text' && w.value.includes(raw)), JSON.stringify(h.writes)); assertSafe(html);
    });
    for (const name of ['mainwp_site_overview_reconnect', 'mainwp_reconnect_with_pw', 'mainwp_managesites_reconnect', 'mainwp_managesites_cards_reconnect', 'mainwp_managesites_bulk_reconnect_specific']) {
        for (const json of [false, true]) {
            test('reconnect rendering ' + name + (json ? ' JSON' : ' plain') + ': ' + raw, () => {
                const h = harness('Site'); load(h, name, name.includes('bulk') ? 'mainwp-managesites-action' : 'mainwp');
                if (name.includes('bulk')) h.row.addClass('error');
                h.context[name](h.row);
                h.row.removeClass('error');
                const body = json ? JSON.stringify({ el_before: 'Before ', el_text: raw, el_link: 'https://docs.mainwp.com/', el_after: ' after' }) : raw;
                h.respond('ERROR ' + body);
                const expected = decodeEntities(raw);
                const values = h.writes.filter(w => w.method === 'text').map(w => w.value).concat(h.writes.filter(w => w.method === 'append' && w.value && typeof w.value.textContent === 'string').map(w => w.value.textContent));
                const html = htmlWrites(h).at(-1);
                assert.ok(values.some(value => value.includes(expected)) || (html && html.includes(escaped(raw))), JSON.stringify(h.writes));
                htmlWrites(h).forEach(assertSafe);
                assert.ok(!JSON.stringify(h.writes).includes('el_before'), 'JSON should be readable');
            });
        }
    }
}

for (const raw of ['?a=1&amp;b=2', '?a=1&not=2']) {
    for (const name of ['mainwp_install_bulk', 'mainwp_upload_bulk']) {
        test('data queue href ' + name + ' ' + raw, () => {
            const h = harness(); load(h, name); h.context[name]('plugins', 'fixture', 'Fixture');
            h.respond({ sites: { 7: { name: 'A &amp; B', url: 'https://child.example/' + raw } } });
            const html = htmlWrites(h).find(value => value.includes('siteBulkInstall'));
            assert.ok(html.includes('href="https://child.example/' + escapeHtml(raw) + '"'), html);
            assert.ok(html.includes('>A &amp; B</a>'), html);
        });
    }
    for (const field of ['type', 'slug', 'cost-id', 'name', 'sites']) {
        test('data Cost Tracker ' + field + ' ' + raw, () => {
            const h = harness('Fixture');
            h.row.attr(field === 'cost-id' ? field : field === 'sites' ? 'item-siteid' : 'item-' + field, raw);
            load(h, 'mainwp_upload_bulk_start_next'); h.context.mainwp_upload_bulk_start_next('plugin', [], false, false);
            const html = htmlWrites(h).find(value => value.includes('multi-add-to='));
            const attr = field === 'cost-id' ? field : field === 'sites' ? 'installed-sites' : 'item-' + field;
            assert.ok(html.includes(attr + '="' + escapeHtml(raw) + '"'), html);
            if (field === 'name') assert.ok(html.includes('(' + escapeHtml(raw) + ')'), html);
        });
    }
    for (const field of ['id', 'siteid', 'niceurl', 'url']) {
        test('data uptime ' + field + ' ' + raw, () => {
            const h = harness(); load(h, 'uptime_monitoring_prepare_items_rows', 'mainwp-uptime');
            h.context.uptime_monitoring_prepare_items_rows([{ id: '7', siteid: '7', niceurl: 'Site', url: 'https://child.example/', [field]: raw }]);
            assert.ok(htmlWrites(h).at(-1).includes(escapeHtml(raw)), htmlWrites(h).at(-1));
        });
    }
    for (const response of ['ERROR', 'Connected &amp; ready']) {
        test('data reconnect ' + response + ' ' + raw, () => {
            const h = harness(raw); load(h, 'mainwp_managesites_bulk_reconnect_specific', 'mainwp-managesites-action');
            h.row.addClass('error'); h.context.mainwp_managesites_bulk_reconnect_specific(h.row); h.row.removeClass('error'); h.respond(response);
            if (response === 'ERROR') assert.ok(h.writes.some(w => w.method === 'text' && w.value.startsWith(raw + ' - ')), JSON.stringify(h.writes));
            else assert.ok(htmlWrites(h).at(-1).includes(escapeHtml(raw + ' - Connected & ready')), htmlWrites(h).at(-1));
        });
    }
}

for (const name of ['mainwp_install_bulk', 'mainwp_upload_bulk']) {
    for (const url of ['javascript:alert(1)', 'data:text/html,test', '//child.example/', 'ftp://child.example/']) {
        test('bulk queues refuse non-http link ' + name + ': ' + url, () => {
            const h = harness(); load(h, name); h.context[name]('plugins', 'fixture', 'Fixture');
            h.respond({ sites: { 7: { name: 'Site', url } } });
            const html = htmlWrites(h).find(value => value.includes('siteBulkInstall'));
            assert.ok(!html.includes('href="' + url), html); assert.ok(html.includes('Site'), html);
        });
    }
}

for (const response of ['reconnect_failed', 'ERROR transport failed', 'Site has been reconnected successfully!']) {
    test('row status reconnect: ' + response, () => {
        const h = harness(); load(h, 'mainwp_managesites_bulk_reconnect_specific', 'mainwp-managesites-action');
        h.row.addClass('error'); h.context.mainwp_managesites_bulk_reconnect_specific(h.row); h.row.removeClass('error'); h.respond(response);
        const failed = response !== 'Site has been reconnected successfully!';
        const html = htmlWrites(h).at(-1);
        assert.ok(html.includes(failed ? 'red times icon' : 'green check icon'), html);
        assert.equal(h.row.classes.has('error'), failed); assert.equal(h.row.classes.has('positive'), !failed);
        assert.doesNotMatch(html, /\[object Object\]/);
        if (response === 'reconnect_failed') assert.ok(h.writes.some(w => w.method === 'append' && typeof w.value === 'object' && w.value.text().includes('Reconnect failed.')), 'append the readable reconnect nodes');
    });
}
for (const response of [{ error: 'Removal failed' }, { result: 'NOSITE' }, { result: 'SUCCESS' }, { result: 'NOMAINWP' }]) {
    test('row status remove: ' + JSON.stringify(response), () => {
        const h = harness(); load(h, 'mainwp_managesites_bulk_remove_specific', 'mainwp-managesites-action');
        h.context.mainwp_managesites_bulk_remove_specific(h.row); h.respond(response);
        const failed = Boolean(response.error) || response.result === 'NOSITE';
        const html = htmlWrites(h).at(-1);
        assert.ok(html.includes(failed ? 'red times icon' : 'green check icon'), html);
        assert.equal(h.timers.length, failed ? 0 : 1);
        h.timers.forEach(fn => fn());
        assert.equal(h.writes.some(w => w.method === 'fadeOut'), !failed);
    });
}

for (const name of ['mainwp_site_overview_reconnect', 'mainwp_reconnect_with_pw', 'mainwp_managesites_reconnect', 'mainwp_managesites_cards_reconnect', 'mainwp_managesites_bulk_reconnect_specific']) {
    for (const [link, kept] of [['https://docs.mainwp.com/', true], ['http://child.example/help', true], ['javascript:alert(1)', false], [' javascript:alert(1)', false], ['data:text/html,x', false], ['&#106;avascript:alert(1)', false]]) {
        test('reconnect error link ' + name + ': ' + link, () => {
            const h = harness('Site'); load(h, name, name.includes('bulk') ? 'mainwp-managesites-action' : 'mainwp');
            if (name.includes('bulk')) h.row.addClass('error');
            h.context[name](h.row);
            h.row.removeClass('error');
            h.respond('ERROR ' + JSON.stringify({ el_before: 'Before ', el_text: 'Community', el_link: link, el_after: ' after' }));
            const hrefs = h.writes.filter(w => w.method === 'attr' && w.selector === '<a>').map(w => w.value.href);
            assert.deepEqual(hrefs, kept ? [decodeEntities(link)] : []);
            const texts = h.writes.filter(w => (w.method === 'append' && w.value && typeof w.value.textContent === 'string') || (w.method === 'text' && w.selector === '<a>')).map(w => w.value.textContent ?? w.value);
            assert.deepEqual(texts, ['Before ', 'Community', ' after']);
        });
    }
}

for (const [group, rows] of scenarios) {
    nodeTest(group, () => {
        for (const { name, run } of rows) {
            try { run(); } catch (error) { error.message = name + ': ' + error.message; throw error; }
        }
    });
}
