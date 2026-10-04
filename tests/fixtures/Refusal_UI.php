<?php
/**
 * Runs AJAX responses through the Dashboard's JavaScript callbacks.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

trait Refusal_UI {

    /** @param string $asset Script name. @param string $handler Selector or bulk. @param string $body AJAX response. @param string $action Expected action. */
    private function assert_refusal_ui( $asset, $handler, $body, $action ): void {
        $ui = $this->run_refusal_ui( $asset, $handler, $body );
        $this->assertSame( array( $action ), $ui['posts'], 'The callback must post the tested action.' );
        $visible_errors = array_filter( $ui['messages'], static function ( $message ) {
            return false !== strpos( $message['style'] ?? '', 'red' ) && false !== strpos( $message['text'] ?? '', 'Error - insufficient permissions!' );
        } );
        $this->assertNotEmpty( $visible_errors, 'Refusal was not rendered as a red error: ' . wp_json_encode( $ui ) );
        $this->assertSame( array(), $ui['fades'], 'Refusal must not fade out the retained row.' );
        $this->assertSame( 0, $ui['reloads'], 'Refusal must not trigger a success reload.' );
        if ( 'bulk' === $handler ) {
            $this->assertSame( 1, $ui['finished'], 'The refused batch must reach completion.' );
        }
    }

    /** @param string $asset Script name. @param string $handler Selector or bulk. @param string $body AJAX responses. @return array UI observations. */
    private function run_refusal_ui( $asset, $handler, $body ): array {
        $script = <<<'JS'
const fs = require('node:fs');
const vm = require('node:vm');
const [asset, handler] = process.argv.slice(1);
const response = fs.readFileSync(0, 'utf8');
const parsed = handler === 'bulk' ? JSON.parse(response) : null;
const responses = handler === 'bulk' ? (Array.isArray(parsed) ? parsed : [parsed]) : [];
let responseIndex = 0;
const handlers = new Map();
const elements = new Map();
const messages = [];
const posts = [];
const fades = [];
let reloads = 0;
const escapeHtml = value => String(value).replaceAll('&', '&amp;').replaceAll('<', '&lt;').replaceAll('>', '&gt;');
function element(selector) {
    if (selector && selector.selector) return selector;
    const key = String(selector);
    if (elements.has(key)) return elements.get(key);
    const state = { html: '', text: '', classes: key, attrs: {}, data: {} };
    const item = {
        selector: key,
        state,
        length: key.includes('status="queue"') ? 0 : 1,
        on(event, selector, callback) {
            if (typeof selector === 'string') handlers.set(selector, callback);
            return item;
        },
        ready(callback) { callback(); return item; },
        find(child) { return element(key + ' ' + child); },
        closest(parent) { return element(key + ' ' + parent); },
        parents(parent) { return element(parent); },
        siblings() { return item; },
        attr(name, value) {
            if (value !== undefined) { state.attrs[name] = value; return item; }
            return state.attrs[name] ?? (name === 'selected-tag-siteids' ? '[1]' : name === 'suspend-status' ? '0' : '1');
        },
        data(name, value) {
            if (value !== undefined) { state.data[name] = value; return item; }
            return state.data[name] ?? false;
        },
        val() { return 'Tag name'; },
        prop() { return item; },
        html(value) {
            if (value === undefined) return state.text ? escapeHtml(state.text) : state.html;
            state.html = value;
            messages.push({ target: key, text: value, style: value });
            return item;
        },
        text(value) {
            if (value === undefined) return state.text;
            state.text = value;
            return item;
        },
        prepend(child) {
            messages.push({ target: key, text: child.state.text, style: child.state.classes });
            return item;
        },
        addClass() { return item; },
        removeClass() { return item; },
        hasClass() { return false; },
        dropdown() { return item; },
        modal() { return item; },
        show() { return item; },
        hide() { return item; },
        fadeOut() { fades.push(key); return item; },
        remove() { return item; }
    };
    elements.set(key, item);
    return item;
}
function jQuery(selector) {
    if (typeof selector === 'function') { selector(); return; }
    return element(selector);
}
jQuery.post = (url, data, callback, type) => {
    posts.push(data.action);
    callback(handler === 'bulk' ? responses[responseIndex++] : type === 'json' ? JSON.parse(response) : response);
};
const context = vm.createContext({
    jQuery, document: {}, URLSearchParams, location: { search: '' }, ajaxurl: 'admin-ajax.php',
    mainwpParams: {}, MainWP: { I18n: { t: text => text } },
    mainwp_secure_data: data => data, confirm: () => true,
    mainwp_confirm: (text, callback) => callback(),
    feedback: (target, text, style) => messages.push({ target, text, style }),
    mainwp_set_message_zone: (target, text, style) => messages.push({ target, text, style }),
    setHtml: (target, text) => messages.push({ target, text, style: '' }),
    setTimeout: callback => callback(), mainwp_forceReload: () => { ++reloads; }
});
vm.runInContext(fs.readFileSync(asset, 'utf8'), context, { filename: asset });
if (handler === 'bulk') {
    context.total = responses.length;
    vm.runInContext('bulkManageClientsTotal = total;', context);
    for (let index = 0; index < responses.length; ++index) {
        context.checked = element('checkbox-' + index);
        context.checked.closest('tr').attr('clientid', String(index + 1));
        vm.runInContext('mainwp_manageclients_bulk_remove_specific(checked);', context);
    }
} else {
    const callback = handlers.get(handler);
    if (!callback) throw new Error('Missing handler: ' + handler);
    callback.call(element(handler), { preventDefault() {}, stopPropagation() {} });
}
const finished = handler === 'bulk' ? vm.runInContext('bulkManageClientsFinished', context) : 0;
process.stdout.write(JSON.stringify({ messages, posts, fades, reloads, finished }));
JS;
        exec( 'command -v node', $node_path, $node_missing );
        if ( 0 !== $node_missing ) {
            $this->fail( 'node must be on PATH: these tests run the shipped JavaScript callbacks.' );
        }
        $process = proc_open( array( 'node', '-e', $script, dirname( __DIR__, 2 ) . '/assets/js/' . $asset, $handler ), array( array( 'pipe', 'r' ), array( 'pipe', 'w' ), array( 'pipe', 'w' ) ), $pipes );
        $this->assertIsResource( $process, 'Could not start the JavaScript callback test.' );
        fwrite( $pipes[0], $body );
        fclose( $pipes[0] );
        $output = stream_get_contents( $pipes[1] );
        $errors = stream_get_contents( $pipes[2] );
        fclose( $pipes[1] );
        fclose( $pipes[2] );
        $this->assertSame( 0, proc_close( $process ), $errors );
        return json_decode( $output, true );
    }
}
