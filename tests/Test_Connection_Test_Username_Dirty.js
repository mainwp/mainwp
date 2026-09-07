'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const script = fs.readFileSync(path.join(__dirname, '..', 'assets', 'js', 'mainwp.js'), 'utf8');
const functionStart = script.indexOf('// Test Connection (Edit Site Page)');
const functionEnd = script.indexOf('let managesites_remove = function', functionStart);
const bindingStart = script.indexOf("jQuery(document).on('input', '#mainwp_managesites_edit_http_user'", functionEnd);
const bindingEnd = script.indexOf('\n    });', bindingStart) + '\n    });'.length;

assert.notEqual(functionStart, -1, 'edit-site Test Connection function must exist');
assert.notEqual(functionEnd, -1, 'edit-site Test Connection function boundary must exist');
assert.notEqual(bindingStart, -1, 'HTTP username input tracking must exist');
assert.ok(bindingEnd > bindingStart, 'HTTP username input tracking boundary must exist');

const snippet = script.slice(functionStart, functionEnd) + `
globalThis.testEditConnection = mainwp_managesites_edit_test;
` + script.slice(bindingStart, bindingEnd);

class FakeField {
    constructor(value = '') {
        this.value = value;
        this.dataStore = new Map();
    }
}

class FakeWrapper {
    constructor(field) {
        this.field = field;
    }

    val() {
        return this.field.value;
    }

    data(name, value) {
        if (typeof value === 'undefined') {
            return this.field.dataStore.get(name);
        }
        this.field.dataStore.set(name, value);
        return this;
    }
}

const fields = new Map([
    ['#mainwp_managesites_edit_siteurl', new FakeField('new-origin.example')],
    ['#mainwp_managesites_edit_siteurl_protocol', new FakeField('https')],
    ['input[name=mainwp_managesites_edit_wpurl_with_www]', new FakeField('none-www')],
    ['#mainwp_managesites_edit_siteid', new FakeField('42')],
    ['#mainwp_managesites_edit_verifycertificate', new FakeField('2')],
    ['#mainwp_managesites_edit_ssl_version', new FakeField('0')],
    ['#mainwp_managesites_edit_forceuseipv4', new FakeField('2')],
    ['#mainwp_managesites_edit_http_user', new FakeField('saved-user')],
    ['#mainwp_managesites_edit_http_pass', new FakeField('fresh-password')]
]);

const documentObject = {};
let usernameInputHandler = null;
const requests = [];

function jQuery(target) {
    if (target === documentObject) {
        return {
            on(eventName, selector, handler) {
                assert.equal(eventName, 'input');
                assert.equal(selector, '#mainwp_managesites_edit_http_user');
                usernameInputHandler = handler;
            }
        };
    }
    if (typeof target === 'string') {
        assert.ok(fields.has(target), `unexpected selector: ${target}`);
        return new FakeWrapper(fields.get(target));
    }
    if (target instanceof FakeField) {
        return new FakeWrapper(target);
    }
    throw new Error('Unexpected jQuery target');
}

const context = {
    document: documentObject,
    jQuery,
    mainwp_prepare_connection_diagnostic_modal: () => {},
    mainwp_request_connection_diagnostic: (data) => requests.push(data),
    mainwp_secure_data: (data) => data
};

vm.createContext(context);
vm.runInContext(snippet, context);

assert.equal(typeof usernameInputHandler, 'function', 'username input handler must register');

context.testEditConnection();
assert.equal(requests[0].http_user, 'saved-user');
assert.equal(requests[0].http_pass, 'fresh-password');
assert.equal(requests[0].http_user_dirty, 0, 'untouched saved username is not asserted as fresh input');

usernameInputHandler.call(fields.get('#mainwp_managesites_edit_http_user'));
context.testEditConnection();

assert.equal(requests[1].http_user, 'saved-user');
assert.equal(requests[1].http_pass, 'fresh-password');
assert.equal(requests[1].http_user_dirty, 1, 'explicitly re-entered same-valued username is marked as fresh input');

console.log('Connection Test username-dirty contract passed.');
