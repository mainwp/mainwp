'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const script = fs.readFileSync(path.join(__dirname, '..', 'assets', 'js', 'mainwp.js'), 'utf8');
const tableStart = script.indexOf('let mainwp_managesites_reconnect = function');
const snippetEnd = script.indexOf('// Connect a new website', tableStart);

assert.notEqual(tableStart, -1, 'table reconnect function must exist');
assert.notEqual(snippetEnd, -1, 'reconnect function boundary must exist');

const reconnectSnippet = script.slice(tableStart, snippetEnd) + `
globalThis.testTableReconnect = mainwp_managesites_reconnect;
globalThis.testCardReconnect = mainwp_managesites_cards_reconnect;
`;

class FakeElement {
    constructor(attributes = {}, contents = []) {
        this.attributes = { ...attributes };
        this.contents = contents;
        this.dataStore = new Map();
        this.visible = true;
    }
}

class FakeContents {
    constructor(element) {
        this.element = element;
        this.nodes = [];
    }

    detach() {
        this.nodes = this.element.contents;
        this.element.contents = [];
        return this;
    }
}

class FakeWrapper {
    constructor(elements = []) {
        this.elements = elements;
    }

    get length() {
        return this.elements.length;
    }

    firstElement() {
        return this.elements[0];
    }

    first() {
        return new FakeWrapper(this.length ? [this.firstElement()] : []);
    }

    filter(callback) {
        return new FakeWrapper(this.elements.filter((element, index) => callback.call(element, index, element)));
    }

    find(selector) {
        assert.equal(selector, ':focus');
        const matches = [];
        this.elements.forEach((element) => {
            element.contents.forEach((item) => {
                if (item.focused) {
                    matches.push(item);
                }
            });
        });
        return new FakeWrapper(matches);
    }

    attr(name, value) {
        const element = this.firstElement();
        if (!element) {
            return typeof name === 'string' && typeof value === 'undefined' ? undefined : this;
        }
        if (typeof name === 'object') {
            Object.entries(name).forEach(([key, item]) => {
                element.attributes[key] = String(item);
            });
            return this;
        }
        if (typeof value === 'undefined') {
            return element.attributes[name];
        }
        element.attributes[name] = String(value);
        return this;
    }

    removeAttr(name) {
        this.elements.forEach((element) => {
            delete element.attributes[name];
        });
        return this;
    }

    data(name, value) {
        const element = this.firstElement();
        if (!element) {
            return undefined;
        }
        if (typeof value === 'undefined') {
            return element.dataStore.get(name);
        }
        element.dataStore.set(name, value);
        return this;
    }

    removeData(name) {
        this.elements.forEach((element) => element.dataStore.delete(name));
        return this;
    }

    children() {
        return new FakeContents(this.firstElement());
    }

    contents() {
        return new FakeContents(this.firstElement());
    }

    empty() {
        this.elements.forEach((element) => {
            element.contents = [];
        });
        return this;
    }

    append(value) {
        this.elements.forEach((element) => {
            if (value instanceof FakeContents) {
                element.contents.push(...value.nodes);
            } else {
                element.contents.push(value);
            }
        });
        return this;
    }

    show() {
        this.elements.forEach((element) => {
            element.visible = true;
        });
        return this;
    }

    hide() {
        this.elements.forEach((element) => {
            element.visible = false;
        });
        return this;
    }

    trigger(name) {
        assert.equal(name, 'focus');
        this.elements.forEach((element) => {
            element.focused = true;
            element.focusCalls = (element.focusCalls || 0) + 1;
        });
        return this;
    }
}

function createHarness(tableRow) {
    const requests = [];
    const events = [];

    function jQuery(target) {
        if (typeof target === 'string') {
            return new FakeWrapper([tableRow]);
        }
        if (target instanceof FakeElement) {
            return new FakeWrapper([target]);
        }
        return target;
    }

    jQuery.post = function (url, data, success) {
        const request = {
            failCallback: null,
            fail(callback) {
                this.failCallback = callback;
                return this;
            },
            reject() {
                this.failCallback();
            },
            resolve(response) {
                success(response);
            }
        };
        requests.push(request);
        return request;
    };

    const context = {
        ajaxurl: '/wp-admin/admin-ajax.php',
        feedback: () => events.push('feedback'),
        jQuery,
        mainwp_forceReload: () => events.push('reload'),
        mainwp_render_connection_request_failure: () => events.push('request-failure'),
        mainwp_render_reconnect_failure: () => events.push('reconnect-failure'),
        mainwp_secure_data: (data) => data,
        setTimeout: (callback) => callback()
    };

    vm.createContext(context);
    vm.runInContext(reconnectSnippet, context);

    return { context, events, requests };
}

function createTableRow() {
    const liveCell = {
        checkboxChecked: true,
        dropdownInitialized: true,
        focused: true,
        focusCalls: 0
    };
    return {
        liveCell,
        row: new FakeElement({ siteid: '1' }, [liveCell])
    };
}

function testTableRestoresDetachedControls(settle) {
    const { liveCell, row } = createTableRow();
    const harness = createHarness(row);

    harness.context.testTableReconnect('1');
    harness.context.testTableReconnect('1');

    assert.equal(harness.requests.length, 1, 'table reconnect is single-flight');
    assert.equal(row.dataStore.get('mainwp-reconnect-in-flight'), true);
    assert.equal(typeof row.contents[0], 'string', 'table shows loading content');

    settle(harness.requests[0]);

    assert.strictEqual(row.contents[0], liveCell, 'the original initialized cell is reattached');
    assert.equal(row.contents[0].dropdownInitialized, true);
    assert.equal(row.contents[0].checkboxChecked, true);
    assert.equal(row.contents[0].focusCalls, 1, 'failure restores focus to the invoking row control');
    assert.equal(row.dataStore.has('mainwp-reconnect-in-flight'), false);
    assert.equal(row.visible, true);
}

function createCard() {
    const liveIcon = { iconInitialized: true };
    return {
        card: new FakeElement({ 'site-id': '1' }, [liveIcon]),
        liveIcon
    };
}

function testCardRestoresOnce(settle) {
    const { card, liveIcon } = createCard();
    const wrapper = new FakeWrapper([card]);
    const harness = createHarness(new FakeElement({ siteid: '1' }, []));

    harness.context.testCardReconnect(wrapper);
    harness.context.testCardReconnect(wrapper);

    assert.equal(harness.requests.length, 1, 'card reconnect is single-flight');
    assert.equal(card.attributes['aria-disabled'], 'true');
    assert.equal(card.attributes['aria-busy'], 'true');
    assert.equal(typeof card.contents[0], 'string', 'card shows loading content');

    settle(harness.requests[0]);

    assert.strictEqual(card.contents[0], liveIcon, 'the original card icon is reattached');
    assert.equal(card.contents[0].iconInitialized, true);
    assert.equal(card.dataStore.has('mainwp-reconnect-in-flight'), false);
    assert.equal(card.attributes['aria-disabled'], undefined);
    assert.equal(card.attributes['aria-busy'], undefined);
    assert.equal(card.visible, true);
}

testTableRestoresDetachedControls((request) => request.resolve({ success: false }));
testTableRestoresDetachedControls((request) => request.reject());
testCardRestoresOnce((request) => request.resolve({ success: false }));
testCardRestoresOnce((request) => request.reject());

{
    const { card } = createCard();
    card.attributes['aria-disabled'] = 'false';
    const wrapper = new FakeWrapper([card]);
    const harness = createHarness(new FakeElement({ siteid: '1' }, []));

    harness.context.testCardReconnect(wrapper);
    harness.requests[0].resolve({ success: true, message: 'Connected' });

    assert.equal(card.attributes['aria-disabled'], 'false', 'success restores the original ARIA state');
    assert.equal(card.attributes['aria-busy'], undefined);
    assert.equal(card.dataStore.has('mainwp-reconnect-in-flight'), false);
    assert.equal(card.visible, false);
    assert.deepEqual(harness.events, ['feedback', 'reload']);
}

console.log('Reconnect lifecycle tests passed (table/card structured failure, transport failure, repeat click, and success).');
