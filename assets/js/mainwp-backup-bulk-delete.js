( function ( root, factory ) {
    'use strict';
    if ( typeof module === 'object' && module.exports ) {
        module.exports = factory;
    } else {
        root.mainwpBackupBulkDelete = factory;
    }
} )( typeof window !== 'undefined' ? window : this, function ( options ) {
    'use strict';
    var selected = new Set(), site = 0, generation = 0, plans = [], busy = false;
    var api = { options: options };
    api.ids = function () { return Array.from( selected ); };
    api.busy = function () { return busy; };
    api.reset = function ( id ) { generation++; site = id; selected.clear(); plans = []; };
    api.setSite = function ( id ) { if ( site !== id ) { api.reset( id ); } };
    api.retain = function ( ids ) {
        if ( busy ) { return; }
        var eligible = new Set( ids.map( Number ) );
        selected.forEach( function ( id ) { if ( ! eligible.has( id ) ) { selected.delete( id ); } } );
        plans = [];
    };
    api.select = function ( id, value ) {
        if ( busy ) { return; }
        id = Number( id );
        if ( id > 0 ) { if ( value ) { selected.add( id ); } else { selected.delete( id ); } }
        plans = [];
    };
    function message( response ) {
        if ( response && response.error ) { return typeof response.error === 'string' ? response.error : options.labels.failed; }
        var errors = response && ( response.refusals || response.errors );
        return ( Array.isArray( errors ) ? errors : [] ).map( function ( error ) {
            return typeof error === 'string' ? error : ( error && typeof error.message === 'string' ? error.message : '' );
        } ).filter( Boolean ).join( ' ' );
    }
    api.preview = async function ( cloud ) {
        if ( busy ) { return []; }
        var token = ++generation, ids = api.ids(), next = [];
        busy = true;
        try {
            for ( var id of ids ) {
                var data = { site_id: site, snapshot_id: id }, response;
                if ( cloud !== undefined ) { data.delete_cloud_copies = cloud ? 1 : 0; }
                try { response = await api.options.request( 'preview', data ); }
                catch ( error ) { response = { error: options.labels.failed }; }
                if ( token !== generation ) { return []; }
                var effect = response && response.would_affect;
                if ( effect && Array.isArray( effect.snapshot_ids ) ) {
                    effect.snapshot_ids = effect.snapshot_ids.map( Number );
                } else { effect = null; }
                var refusal = message( response );
                if ( effect && effect.snapshot_ids.some( function ( member ) { return ! selected.has( Number( member ) ); } ) ) {
                    refusal = options.labels.chain;
                }
                next.push( { id: id, effect: effect, warnings: response && Array.isArray( response.warnings ) ? response.warnings : [],
                    error: refusal || ( ! response || ! effect || effect.snapshot_ids.indexOf( id ) === -1 ? options.labels.failed : ( true !== response.can_proceed ? options.labels.refused : '' ) ) } );
            }
            plans = next;
            return next;
        } finally { busy = false; }
    };
    api.confirm = async function () {
        if ( busy || ! plans.length ) { return []; }
        var token = generation, confirmed = new Set(), results = [], pending = plans.slice();
        plans = [];
        busy = true;
        try {
            for ( var plan of pending ) {
                if ( token !== generation ) { break; }
                var result = { id: plan.id, status: 'failed', message: plan.error || options.labels.failed };
                if ( confirmed.has( plan.id ) ) {
                    result.status = 'skipped'; result.message = options.labels.skipped;
                } else if ( ! plan.error ) {
                    var effect = plan.effect;
                    // A preceding confirmed chain deletion may narrow this set, never widen it.
                    var expected = effect.snapshot_ids.filter( function ( id ) { return ! confirmed.has( Number( id ) ); } );
                    try {
                        var response = await api.options.request( 'delete', {
                            site_id: site, snapshot_id: plan.id, expected_snapshot_ids: expected,
                            delete_cloud_copies: effect.delete_cloud_copies ? 1 : 0
                        } );
                        if ( token !== generation ) { break; }
                        var deleted = ( response && Array.isArray( response.deleted_ids ) ? response.deleted_ids : [] ).map( Number ).filter( function ( id ) {
                            return expected.indexOf( id ) !== -1 && selected.has( id );
                        } );
                        deleted.forEach( function ( id ) { confirmed.add( id ); selected.delete( id ); } );
                        if ( deleted.length ) { options.remove( deleted ); }
                        result.status = confirmed.has( plan.id ) ? 'deleted' : 'failed';
                        result.message = message( response ) || ( result.status === 'deleted' ? '' : ( response && response.status === 'refused' ? options.labels.refused : options.labels.changed ) );
                        result.details = response;
                    } catch ( error ) { result.message = options.labels.failed; }
                }
                results.push( result );
                options.changed( results.slice() );
            }
            return results;
        } finally { busy = false; }
    };
    return api;
} );
