'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const test = require('node:test');
const source = fs.readFileSync(require.resolve('../../pages/backup/page-mainwp-backup-storage-view.php'), 'utf8');

const NOTE = '#mainwp-storage-path-style-note > span';
const CHECKBOX = '#mainwp-storage-path-style';

function storageForm() {
    const values = {};
    const checked = {};
    const handlers = {};
    const requests = [];
    const row = {'destination-id': 7, name: 'Offsite', provider: 's3'};
    function jQuery(selector) {
        const fields = String(selector).split(',').map(field => field.trim());
        const node = {
            val(value) {
                if (arguments.length === 0) return values[selector] ?? '';
                for (const field of fields) values[field] = String(value);
                return node;
            },
            is(state) { return state === ':checked' && !!checked[selector]; },
            prop(name, value) {
                if (name === 'checked' && arguments.length > 1) {
                    for (const field of fields) checked[field] = !!value;
                }
                return node;
            },
            text(value) {
                if (arguments.length === 0) return values[selector + ':text'] ?? '';
                values[selector + ':text'] = String(value);
                return node;
            },
            dropdown(action, value) { if (action === 'set selected') values[selector] = value; return node; },
            hide() { return node; }, closest() { return node; }, data(key) { return row[key]; },
            on(events, callback) {
                for (const field of fields) {
                    for (const event of String(events).split(' ')) handlers[field + ' ' + event] = callback;
                }
                return node;
            }
        };
        return node;
    }
    jQuery.post = (url, data, callback) => requests.push(callback);
    const context = {jQuery, URL, ajaxurl: '', mainwp_secure_data: data => data,
        storageModal: {modal() {}}, formFeedback() {}};
    vm.createContext(context);
    const collectStart = source.indexOf('let collectSettings = function');
    const collectEnd = source.indexOf("jQuery( '#mainwp-storage-provider' ).on", collectStart);
    const resetStart = source.indexOf('let resetForm = function');
    const resetEnd = source.indexOf('let storageModal = ', resetStart);
    const mapStart = source.indexOf('let settingsFieldMap = ');
    const editEnd = source.indexOf("jQuery( '.mainwp-storage-test' ).on", mapStart);
    const script = source.slice(collectStart, collectEnd) + source.slice(resetStart, resetEnd)
        + source.slice(mapStart, editEnd) + '\nglobalThis.form = {destinationData, resetForm};';
    // Keep translated strings readable so each note can be told apart.
    vm.runInContext(script
        .replace(/<\?php echo esc_js\( __\( '([^']*)', 'mainwp' \) \); \?>/g, '$1')
        .replace(/<\?php[\s\S]*?\?>/g, 'COPY'), context);

    // Typing fires the field's input event; clicking the toggle fires change.
    const fire = (field, event) => handlers[field + ' ' + event] && handlers[field + ' ' + event].call({});
    const type = (field, value) => {
        values[field] = value;
        fire(field, 'input');
    };
    const form = {
        values, checked,
        type,
        endpoint: value => type('#mainwp-storage-endpoint', value),
        bucket: value => type('#mainwp-storage-bucket', value),
        click(state) {
            checked[CHECKBOX] = state;
            fire(CHECKBOX, 'change');
        },
        note: () => values[NOTE + ':text'] ?? '',
        pathStyle: () => !!checked[CHECKBOX],
        payload: () => context.form.destinationData('mainwp_storage_save')['settings[path_style]'],
        add() { context.form.resetForm(); return form; },
        edit(settings) {
            handlers['.mainwp-storage-edit click'].call({}, {preventDefault() {}});
            requests[requests.length - 1]({provider: 's3', settings});
            return form;
        }
    };
    return form;
}

const serviceEndpoints = [
    ['', 'the Amazon S3 default'],
    ['s3.amazonaws.com', 'the global Amazon S3 host'],
    ['https://s3.us-west-2.amazonaws.com', 'a regional Amazon S3 host'],
    ['s3-eu-west-1.amazonaws.com', 'a legacy dash-region Amazon S3 host'],
    ['https://s3.us-gov-west-1.amazonaws.com', 'a GovCloud regional Amazon S3 host'],
    ['https://S3.Wasabisys.com/', 'Wasabi with mixed case and a slash'],
    ['https://s3.dualstack.us-west-2.amazonaws.com', 'a dual-stack Amazon S3 host'],
    ['s3.eu-central-1.wasabisys.com', 'a regional Wasabi host'],
    ['s3.wasabisys.com.', 'Wasabi with a trailing dot'],
    ['https://s3.wasabisys.com:443', 'Wasabi with an explicit port']
];

for (const [endpoint, label] of serviceEndpoints) {
    test(`a dotted bucket on ${label} turns on path style and says why`, () => {
        const form = storageForm().add();
        form.endpoint(endpoint);
        form.bucket('backups.example.com');
        assert.equal(form.pathStyle(), true);
        assert.match(form.note(), /^Turned on because the bucket name contains a dot/);
        assert.equal(form.payload(), 1);
    });
}

test('the bucket can be typed before the endpoint', () => {
    const form = storageForm().add();
    form.endpoint('https://storage.example.net');
    form.bucket('backups.example.com');
    assert.equal(form.pathStyle(), false);
    form.endpoint('https://s3.wasabisys.com');
    assert.equal(form.pathStyle(), true);
    assert.equal(form.payload(), 1);
});

test('a bucket without a dot keeps virtual-hosted addressing', () => {
    const form = storageForm().add();
    form.endpoint('s3.wasabisys.com');
    form.bucket('backups-example');
    assert.equal(form.pathStyle(), false);
    assert.equal(form.note(), '');
    assert.equal(form.payload(), 0);
});

test('removing the dot undoes the automatic choice', () => {
    const form = storageForm().add();
    form.endpoint('s3.wasabisys.com');
    form.bucket('backups.example');
    assert.equal(form.pathStyle(), true);
    form.bucket('backups-example');
    assert.equal(form.pathStyle(), false);
    assert.equal(form.note(), '');
});

const lookalikes = [
    'https://s3.amazonaws.com.example.net',
    'https://s3.wasabisys.com.example.net',
    'https://mys3.amazonaws.com.example.net',
    'https://notwasabisys.com',
    'https://s3.notwasabisys.com',
    'https://example.net/s3.amazonaws.com',
    'https://s3.amazonaws.com@example.net',
    'https://storage.example.net/?host=s3.wasabisys.com',
    'http://s3.wasabisys.com'
];

// Amazon hosts that are not s3.amazonaws.com, s3.<region>, s3-<region> or
// s3.dualstack.<region>: website, control and other non-storage endpoints.
const amazonNearMisses = [
    's3-website-us-east-1.amazonaws.com',
    's3-website.us-east-1.amazonaws.com',
    's3.website.amazonaws.com',
    's3.dualstack.amazonaws.com',
    's3-dualstack.us-east-1.amazonaws.com',
    's3-external-1.amazonaws.com',
    's3-control.amazonaws.com',
    's3-fips.us-east-1.amazonaws.com',
    's3.us-east-1.amazonaws.com.cn'
];

for (const host of amazonNearMisses) {
    test(`${host} is not a standard Amazon S3 storage endpoint`, () => {
        const form = storageForm().add();
        form.endpoint(host);
        form.bucket('backups.example.com');
        assert.equal(form.pathStyle(), false);
        assert.equal(form.payload(), 0);
        assert.match(form.note(), /^This bucket name contains a dot/);
    });
}

for (const endpoint of lookalikes) {
    test(`${endpoint} is not treated as Amazon S3 or Wasabi`, () => {
        const form = storageForm().add();
        form.endpoint(endpoint);
        form.bucket('backups.example.com');
        assert.equal(form.pathStyle(), false);
        assert.equal(form.payload(), 0);
    });
}

test('a custom https endpoint gets a suggestion and no forced change', () => {
    const form = storageForm().add();
    form.endpoint('https://storage.example.net');
    form.bucket('backups.example.com');
    assert.equal(form.pathStyle(), false);
    assert.match(form.note(), /^This bucket name contains a dot/);
    form.bucket('backups-example');
    assert.equal(form.note(), '');
});

test('the custom suggestion goes away once path style is on', () => {
    const form = storageForm().add();
    form.endpoint('https://storage.example.net');
    form.bucket('backups.example.com');
    form.click(true);
    assert.equal(form.note(), '');
});

for (const endpoint of ['http://192.168.1.20:9000', 'https://127.0.0.1:9000', 'http://localhost:9000']) {
    test(`${endpoint} gets no suggestion because it always uses path style`, () => {
        const form = storageForm().add();
        form.endpoint(endpoint);
        form.bucket('backups.example.com');
        assert.equal(form.note(), '');
        assert.equal(form.pathStyle(), false);
    });
}

test('turning path style off after the automatic choice sticks', () => {
    const form = storageForm().add();
    form.endpoint('s3.wasabisys.com');
    form.bucket('backups.example.com');
    form.click(false);
    form.bucket('backups.example.org');
    form.endpoint('s3.us-east-2.wasabisys.com');
    form.type('#mainwp-storage-region', 'us-east-2');
    assert.equal(form.pathStyle(), false);
    assert.equal(form.payload(), 0);
});

test('turning path style on by hand is kept when the bucket loses its dot', () => {
    const form = storageForm().add();
    form.endpoint('s3.wasabisys.com');
    form.click(true);
    form.bucket('backups.example');
    form.bucket('backups-example');
    assert.equal(form.pathStyle(), true);
    assert.equal(form.payload(), 1);
});

test('a choice made in one add session does not carry into the next', () => {
    const form = storageForm().add();
    form.endpoint('s3.wasabisys.com');
    form.bucket('backups.example.com');
    form.click(false);
    form.add();
    assert.equal(form.note(), '');
    form.endpoint('s3.wasabisys.com');
    form.bucket('backups.example.com');
    assert.equal(form.pathStyle(), true);
});

test('editing a saved destination without path style keeps it off', () => {
    const form = storageForm();
    form.edit({endpoint: 's3.wasabisys.com', bucket: 'backups.example.com', path_style: 0});
    assert.equal(form.pathStyle(), false);
    assert.match(form.note(), /^This bucket name contains a dot/, 'the saved value stays, with the suggestion shown');
    form.type('#mainwp-storage-name', 'Renamed');
    form.bucket('backups.example.org');
    assert.equal(form.pathStyle(), false);
    assert.equal(form.payload(), 0);
});

test('editing a saved destination with path style keeps it on', () => {
    const form = storageForm();
    form.edit({endpoint: 'https://storage.example.net', bucket: 'backups-example', path_style: 1});
    form.bucket('backups-example2');
    assert.equal(form.pathStyle(), true);
    assert.equal(form.payload(), 1);
});

test('opening a destination after an add session starts from its saved value', () => {
    const form = storageForm().add();
    form.endpoint('s3.wasabisys.com');
    form.bucket('backups.example.com');
    assert.equal(form.pathStyle(), true);
    form.edit({endpoint: 's3.wasabisys.com', bucket: 'backups.example.com', path_style: 0});
    assert.equal(form.pathStyle(), false);
    assert.equal(form.payload(), 0);
});
