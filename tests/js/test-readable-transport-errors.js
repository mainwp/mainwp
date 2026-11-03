'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const test = require('node:test');

const main = fs.readFileSync(require.resolve('../../assets/js/mainwp.js'), 'utf8');
const imports = fs.readFileSync(require.resolve('../../assets/js/mainwp-managesites-import.js'), 'utf8');
const setup = fs.readFileSync(require.resolve('../../assets/js/mainwp-setup.js'), 'utf8');
const raw = 'Details: <img src=x onerror=alert(1)> > & "quoted"';

function context(result) {
    const nodes = new Map();
    function jQuery(selector) {
        if (typeof selector === 'function') return;
        if (!nodes.has(selector)) {
            const node = { value: '', text(value) { this.value = value; return this; },
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
        mainwp_failure_ux_nodes: () => { throw new Error('Generic diagnostics hid the transport message'); },
    });
    return { sandbox, nodes };
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
        const { sandbox } = context({ error_message: raw, failure_ux: '<div>generic</div>' });
        vm.runInContext(branch, sandbox);
        assert.equal(sandbox.errors[0].value, (name === 'CSV import' ? '[3] >> ' : '') + raw);
    });
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

test('Legacy wizard check escapes details once before the HTML message zone', () => {
    const expression = setup.match(/mainwp_set_message_zone\('#mainwp-message-zone', (response === 'HTTPERROR'[^\n]+), 'red'\);/);
    assert.ok(expression, 'Legacy wizard callback must include its transport message');
    const { sandbox } = context({ error_message: raw, failure_ux: '<div>generic</div>' });
    sandbox.mainwp_onboarding_escape = value => value.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    assert.equal(vm.runInContext(expression[1], sandbox), sandbox.mainwp_onboarding_escape(raw));
});
