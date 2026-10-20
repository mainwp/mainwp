'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const test = require('node:test');
const source = fs.readFileSync(require.resolve('../../pages/backup/page-mainwp-backup-settings-view.php'), 'utf8');
const start = source.indexOf('function syncBackupSettingsVisibility()');
const end = source.indexOf('\n                }', start) + '\n                }'.length;
function visibility({enabled = true, primary = '', archive = 'tar.gz', encryption = false, diagnostics = false} = {}) {
    const shown = {};
    const values = {'#mainwp_primaryBackup': primary, '#mainwp_archiveFormat': archive};
    const checked = {'#mainwp_options_enableNativeBackups': enabled, '#mainwp_options_enableBackupEncryption': encryption, '#mainwp_options_backupDiagnosticsEnabled': diagnostics};
    const context = {$: selector => ({is: () => !!checked[selector], val: () => values[selector],
        toggle(value) { shown[selector] = value; return this; }, closest() { return this; }})};
    vm.createContext(context);
    vm.runInContext(source.slice(start, end).replace(/<\?php[\s\S]*?\?>/g, 'false'), context);
    context.syncBackupSettingsVisibility();
    return shown;
}
test('disabled native backups and external providers hide every dependent group', () => {
    for (const state of [{enabled: false, archive: 'zip', encryption: true, diagnostics: true}, {primary: 'external', archive: 'zip', encryption: true, diagnostics: true}]) {
        assert.ok(Object.values(visibility(state)).every(value => value === false));
    }
});
test('ZIP, encryption, and diagnostics groups follow their own parent settings', () => {
    const plain = visibility();
    assert.equal(plain['.mainwp-native-backup-field'], true);
    assert.equal(plain['#mainwp_options_backupEncryptionPassphrase'], false);
    const enabled = visibility({archive: 'zip', encryption: true, diagnostics: true});
    assert.ok(Object.values(enabled).every(value => value === true));
    const tar = visibility({archive: 'tar.gz', encryption: true});
    assert.equal(tar['#mainwp_maximumFileDescriptorsAuto, #mainwp_options_maximumFileDescriptors, #mainwp_options_loadFilesBeforeZip'], false);
    assert.equal(tar['#mainwp_options_backupEncryptionPassphrase'], true);
});
