'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const test = require('node:test');
const { document, loadSiteNameHelper } = require('./fixtures/site-name-dom');

const main = fs.readFileSync(require.resolve('../../assets/js/mainwp.js'), 'utf8');
const imports = fs.readFileSync(require.resolve('../../assets/js/mainwp-managesites-import.js'), 'utf8');
const setup = fs.readFileSync(require.resolve('../../assets/js/mainwp-setup.js'), 'utf8');
const raw = 'Details: <img src=x onerror=alert(1)> > & "quoted"';

function context(result) {
    const nodes = new Map();
    const panelMarker = { panel: true };
    function jQuery(selector) {
        if (typeof selector === 'function') return;
        if (!nodes.has(selector)) {
            const node = { value: '', attributes: new Map(), text(value) { this.value = value; return this; },
                attr(key, value) { this.attributes.set(key, value); return this; },
                prop() { return this; },
                empty() { this.value = ''; return this; }, html() { throw new Error('Transport text reached an HTML sink'); } };
            const proxy = new Proxy(node, { get(target, key) { return key in target ? target[key] : () => proxy; } });
            nodes.set(selector, proxy);
        }
        return nodes.get(selector);
    }
    jQuery.fn = {};
    const sandbox = vm.createContext({ jQuery, document: {}, console, URL,
        mainwpParams: {}, window: {}, mainwpVars: {},
        MainWP: { I18n: { t: value => value } },
        errors: [], response: 'HTTPERROR', res_things: result,
        check_result: '[3] >> ', state: { status: 'unreachable', result, minutes: null },
        mainwp_feedback_linked_message: text => ({ value: text }),
        mainwp_failure_ux_nodes: () => panelMarker,
    });
    return { sandbox, nodes, panelMarker };
}

for (const [name, source, start] of [
    ['Add Site', main, "if (response == 'HTTPERROR' && res_things.error_message)"],
    ['CSV import', imports, "if (response == 'HTTPERROR')"],
]) {
    const begin = source.indexOf(start);
    const end = source.indexOf("} else if (response == 'NOMAINWP')", begin);
    assert.ok(begin >= 0 && end > begin, `${name} must retain its HTTPERROR branch`);
    const branch = source.slice(begin, end) + '}';
    test(`${name} prefers literal transport text, including when diagnostics exist`, () => {
        const { sandbox, panelMarker } = context({ error_message: raw, failure_ux: '<div>generic</div>' });
        vm.runInContext(branch, sandbox);
        assert.equal(sandbox.errors[0].value, (name === 'CSV import' ? '[3] >> ' : '') + raw);
        if (name === 'Add Site') {
            assert.equal(sandbox.errors.length, 2);
            assert.equal(sandbox.errors[1], panelMarker);
        }
    });
    if (name === 'Add Site') {
        test('Add Site shows only transport text when diagnostics are absent', () => {
            const { sandbox } = context({ error_message: raw });
            vm.runInContext(branch, sandbox);
            assert.equal(sandbox.errors.length, 1);
            assert.equal(sandbox.errors[0].value, raw);
        });
    }
    test(`${name} keeps its existing fallback`, () => {
        const { sandbox } = context({});
        vm.runInContext(branch, sandbox);
        assert.match(sandbox.errors[0].value, name === 'CSV import' ? /HTTP error: website does not exist!/ : /This site can not be reached!/);
    });
}

for (const message of [raw, undefined]) {
    test(`Wizard detection uses text and ${message ? 'prefers details' : 'keeps the fallback'}`, () => {
        const { sandbox, nodes } = context({ error_message: message });
        vm.runInContext(setup, sandbox);
        vm.runInContext(`mainwp_onboarding_fields = () => ({ url: 'https://example.test/' });
            mainwp_onboarding_copy = key => key === 'unreachable' ? 'Cannot reach %s' : key;
            mainwp_onboarding_render_time = () => {};
            mainwp_onboarding_render_detection(state);`, sandbox);
        assert.equal(nodes.get('#mainwp-onboarding-detection-message').value, message || 'Cannot reach https://example.test/');
    });
}

test('Error tooltips decode entities as attribute text and preserve the returned message', () => {
    const { sandbox } = context({});
    sandbox.document = document;
    loadSiteNameHelper(vm, main, sandbox);
    for (const name of ['getErrorMessage', 'getErrorMessageInfo']) {
        const start = main.indexOf('globalThis.' + name + ' = ');
        assert.ok(start >= 0, name);
        const end = main.indexOf('\n}', start);
        assert.ok(end > start, name + ' ends');
        vm.runInContext(main.slice(start, end + 2), sandbox);
    }
    const error = { message: 'The site&#039;s certificate &lt;b&gt;details&lt;/b&gt;' };
    const tooltip = sandbox.getErrorMessageInfo(error, 'ui');
    assert.equal(tooltip.attributes.get('data-tooltip'), "The site's certificate <b>details</b>");
    assert.equal(sandbox.getErrorMessage(error), error.message);
    assert.equal(sandbox.getErrorMessageInfo(error), error.message);
});
