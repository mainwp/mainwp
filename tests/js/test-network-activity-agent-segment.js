const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');

const source = fs.readFileSync(process.argv[2] || path.join(__dirname, '../../modules/logs/classes/class-log-events-filter-segment.php'), 'utf8');
const script = source.match(/<script type="text\/javascript">([\s\S]*?)<\/script>/)[1];
const handlers = new Map();
let agent = 'mainwp_mcp';
let segment = {};
let posted;
let applied = 0;
const document = {};

function jquery(selector) {
    const node = {
        length: 1,
        ready(callback) { callback(jquery); return node; },
        on(event, callback) { handlers.set(selector, callback); return node; },
        val() { return selector === '#mainwp-common-filter-option-name' ? 'module_log_manage' : 'Fixture'; },
        attr(name) { return name === 'segment-filters' ? JSON.stringify(segment) : node; },
        dropdown(command, value) {
            const isAgent = typeof selector === 'string' && (selector.includes('filter-agent') || selector.includes('.seg_agent'));
            if (command === 'get value') {
                return isAgent ? agent : 'fixture';
            }
            if (isAgent && command === 'clear') agent = '';
            if (isAgent && command === 'set selected') agent = value[0];
            return node;
        },
    };
    for (const method of ['html', 'addClass', 'hide', 'show', 'modal', 'fadeOut', 'removeAttr', 'calendar']) {
        node[method] = () => node;
    }
    return node;
}
jquery.post = (url, data) => { posted = data; };
const context = {
    jQuery: jquery,
    document,
    console,
    ajaxurl: '/fixture',
    mainwp_secure_data: data => data,
    __: text => text,
    mainwpSegmentModalUiHandle: { hideSegmentStatus() {} },
    mainwp_module_log_overview_content_filter() { applied++; },
};
vm.runInNewContext(script, context);

handlers.get('#mainwp-common-filter-edit-segment-save')();
assert.equal(posted.seg_agent, 'mainwp_mcp', 'Saving a segment must include its agent.');
assert.equal(posted.seg_source, 'fixture', 'Existing source selection must still be saved.');

segment = { name: 'MCP', seg_agent: 'mainwp_mcp', seg_source: 'dashboard' };
agent = '';
handlers.get('#mainwp-common-filter-select-segment-choose-button')();
assert.equal(agent, 'mainwp_mcp', 'Loading a segment must restore its agent.');

segment = { name: 'Before agents', seg_source: 'dashboard' };
handlers.get('#mainwp-common-filter-select-segment-choose-button')();
assert.equal(agent, '', 'An older segment must clear a previously selected agent.');
assert.equal(applied, 2, 'Both loaded segments must apply the filters.');

const listSource = fs.readFileSync(path.join(__dirname, '../../modules/logs/classes/class-log-events-list-table.php'), 'utf8');
const defaultCondition = listSource.match(/if \(\s*(\( data.source ===[\s\S]*?)\s*\) \{\s*show_filter = false;/)[1];
const hidesFilters = new Function('data', `return (${defaultCondition});`);
const defaults = { source: '', events: '', sites: '', group: '', client: '', user: '', range: 'thismonth', current_client_id: '', current_site_id: '' };
assert.equal(hidesFilters(defaults), true, 'Older default requests must still hide the filter row.');
assert.equal(hidesFilters({ ...defaults, agent: '' }), true, 'All Agents must retain default visibility.');
assert.equal(hidesFilters({ ...defaults, agent: 'mainwp_mcp' }), false, 'An active MCP agent must keep filters and Reset visible.');
