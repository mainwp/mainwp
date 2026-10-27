'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');

const sourcePath = process.env.SEC39_JS_SOURCE || path.join(__dirname, '../../assets/js/mainwp-managesites-import.js');
const context = vm.createContext({
    document: {},
    jQuery: () => ({ ready() {} }),
    MainWP: { I18n: { t: text => text } },
});
vm.runInContext(fs.readFileSync(sourcePath, 'utf8'), context, { filename: sourcePath });

function certificateValue(expression) {
    const html = vm.runInContext(expression, context);
    const input = html.match(/<input\b[^>]*name="mainwp_managesites_import\[0\]\[verify_certificate\]"[^>]*>/);
    assert.ok(input, 'Rendered row must contain a certificate control');
    const value = input[0].match(/\bvalue="([^"]*)"/);
    assert.ok(value, 'Certificate control must have a value attribute');
    return value[1];
}

for (const renderer of ['mainwp_managesites_import_sites_page_setup_add_row', 'mainwp_managesites_import_sites_add_row']) {
    test(`${renderer}: untouched rows inherit the global policy`, () => {
        assert.equal(certificateValue(`${renderer}(1)`), '');
    });
}

for (const value of ['0', '1', '2']) {
    test(`Explicit certificate choice ${value} remains in the rendered control`, () => {
        const expression = `mainwp_managesites_import_sites_render_input(0, 'verify_certificate', '', '', 'number', '${value}')`;
        assert.equal(certificateValue(expression), value);
    });
}
