import fs from 'node:fs';
import path from 'node:path';
import { createRequire } from 'node:module';
import { fileURLToPath, pathToFileURL } from 'node:url';
import { SourceTextModule, SyntheticModule } from 'node:vm';

const harness = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');

// The pinned CLI has no library export. Expose its existing functions in memory, without
// changing the installed package or enabling its file-output or command-dispatch paths.
export async function loadPinnedSuite() {
  const entry = path.join(harness, 'node_modules/@modelcontextprotocol/conformance/dist/index.js');
  const pkg = JSON.parse(fs.readFileSync(path.join(path.dirname(entry), '../package.json'), 'utf8'));
  const source = fs.readFileSync(entry, 'utf8');
  const tail = 'Nu.parse();export{};';
  if (pkg.version !== '0.2.0-alpha.12' || source.split(tail).length !== 2) throw new Error('pinned conformance adapter requires review');
  const module = new SourceTextModule(source.replace(tail, 'void 0;export { Vc as authorizationScenario, mc as authorizationNames, ol as matchBaseline, ic as serverScenario, ac as authorizationDefinition };'), {
    identifier: pathToFileURL(entry).href,
    initializeImportMeta(meta) { meta.url = pathToFileURL(entry).href; },
  });
  const require = createRequire(entry);
  await module.link(async (specifier) => {
    const resolved = require.resolve(specifier);
    const imported = await import(resolved.startsWith('/') ? pathToFileURL(resolved).href : resolved);
    const names = Object.keys(imported);
    return new SyntheticModule(names, function () {
      for (const name of names) this.setExport(name, imported[name]);
    });
  });
  await module.evaluate();
  return module.namespace;
}

async function main() {
  const suite = await loadPinnedSuite();
  const args = process.argv.slice(2);
  const value = (name) => args[args.indexOf(name) + 1];
  const settings = { url: value('--url'), clientId: value('--client-id'), resource: value('--resource'), port: Number(value('--port')) };
  if (args.some((arg) => ['-o', '--output-dir', '--file'].includes(arg))) throw new Error('authorization file output is forbidden');
  const checks = await collectAuthorizationChecks(suite, settings);
  process.send?.({ checks });
  process.exitCode = checks.some((check) => check.status !== 'SUCCESS') ? 1 : 0;
}

export async function collectAuthorizationChecks(suite, settings) {
  const context = {};
  const checks = [];
  for (const name of suite.authorizationNames()) {
    const result = await suite.authorizationScenario(settings, name, context);
    if (result.checks[0]?.status === 'SUCCESS' && result.checks[0].details) context[name] = result.checks[0].details;
    // Callback URLs and token responses stay in this process. Only verdict fields cross IPC.
    checks.push(...result.checks.map(({ id, status }) => ({ id, status })));
  }
  return checks;
}

if (process.argv[1] && path.resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
  main().catch(() => {
    console.error('authorization conformance could not complete');
    process.exitCode = 1;
  });
}
