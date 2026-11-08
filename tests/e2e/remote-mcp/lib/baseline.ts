// Baselines name one check of a required server scenario, with a class and reason.
// Whole scenarios would also hide wire-schema failures appended by the pinned suite.
//   (a) a capability v1 doesn't declare
//   (b) a fixture tool or prompt a real server doesn't have
//   (c) a scenario that would only test the harness proxy

export interface BaselineEntry {
  id: string;
  klass: string | null;
  reason: string;
  line: number;
}

export function parseBaseline(text: string): { sections: string[]; entries: BaselineEntry[] } {
  const sections: string[] = [];
  const entries: BaselineEntry[] = [];
  text.split('\n').forEach((raw, index) => {
    const line = raw.replace(/\s+$/, '');
    if (line === '' || /^\s*#/.test(line)) return;
    const section = /^([A-Za-z_-]+):\s*(#.*)?$/.exec(line);
    if (section) {
      sections.push(section[1]);
      return;
    }
    const entry = /^\s+-\s+([^\s#]+)\s*(?:#\s*(?:\(([a-z])\)\s*)?(.*))?$/.exec(line);
    if (entry) {
      entries.push({ id: entry[1], klass: entry[2] ?? null, reason: (entry[3] ?? '').trim(), line: index + 1 });
    } else {
      entries.push({ id: line.trim(), klass: null, reason: '', line: index + 1 });
    }
  });
  return { sections, entries };
}

export function baselineProblems(text: string, requiredScenarios: string[]): string[] {
  const problems: string[] = [];
  const { sections, entries } = parseBaseline(text);
  if (sections.length !== 1 || sections[0] !== 'server') problems.push('the only section must be server:');
  const wholesale = new Set<string>();
  const perCheck = new Set<string>();
  for (const entry of entries) {
    const [scenario, check] = entry.id.split(':');
    if (!requiredScenarios.includes(scenario)) problems.push(`line ${entry.line}: ${scenario} is not a required server scenario`);
    if (!entry.klass || !['a', 'b', 'c'].includes(entry.klass)) problems.push(`line ${entry.line}: no (a), (b) or (c) class`);
    if (entry.reason.length < 8) problems.push(`line ${entry.line}: no reason`);
    if (!check || entry.id.split(':').length !== 2) problems.push(`line ${entry.line}: baseline must name one check`);
    if (check?.startsWith('wire-schema-') || check === 'scenario-timeout') problems.push(`line ${entry.line}: infrastructure and wire checks cannot be excluded`);
    (check === undefined ? wholesale : perCheck).add(scenario);
  }
  for (const scenario of perCheck) {
    if (wholesale.has(scenario)) problems.push(`${scenario} is listed both wholesale and per check`);
  }
  if (new Set(entries.map((e) => e.id)).size !== entries.length) problems.push('an entry is listed twice');
  return problems;
}

/** Server scenario names from `conformance list --server --requirements <rev>`. */
export function requiredServerScenarios(listing: string): string[] {
  const out: string[] = [];
  let inServer = false;
  for (const line of listing.split('\n')) {
    if (/^Server scenarios/.test(line)) {
      inServer = true;
      continue;
    }
    if (inServer && /^\s+-\s+(\S+)\s*$/.test(line)) {
      out.push(line.trim().slice(2).trim());
      continue;
    }
    if (inServer && line.trim() !== '') inServer = false;
  }
  return out;
}
