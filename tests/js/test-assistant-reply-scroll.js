'use strict';

const { test } = require( 'node:test' );
const assert = require( 'node:assert/strict' );
const { assistantReplyScrollTarget } = require( '../../assets/js/mainwp-assistant-chat-logic.js' );

test( 'a tall reply opens at its top with room to see the bubble edge', () => {
    assert.equal( assistantReplyScrollTarget( 720, 1000, 290, 1740 ), 712 );
} );

test( 'a short reply opens at the bottom', () => {
    assert.equal( assistantReplyScrollTarget( 720, 100, 290, 840 ), 550 );
} );

test( 'a reply exactly as tall as the view opens at the bottom', () => {
    assert.equal( assistantReplyScrollTarget( 720, 290, 290, 1030 ), 740 );
} );

test( 'the reply target stays within the scroll range', () => {
    assert.equal( assistantReplyScrollTarget( 4, 1000, 290, 1016 ), 0 );
    assert.equal( assistantReplyScrollTarget( 720, 1000, 290, 900 ), 610 );
    assert.equal( assistantReplyScrollTarget( 0, 100, 290, 100 ), 0 );
} );
