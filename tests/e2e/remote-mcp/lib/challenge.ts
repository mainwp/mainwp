/**
 * Parameters of a single `Bearer` challenge (RFC 6750 3). Null for any other scheme, so a
 * WordPress or proxy challenge never reads as ours.
 */
export function parseBearerChallenge(header: string | undefined): Record<string, string> | null {
  const match = /^\s*Bearer(?:\s+(.*))?$/is.exec(header ?? '');
  if (!match) {
    return null;
  }
  const params: Record<string, string> = {};
  const re = /([A-Za-z_][A-Za-z0-9_-]*)\s*=\s*(?:"((?:[^"\\]|\\.)*)"|([^\s,]+))\s*(?:,|$)/g;
  let m: RegExpExecArray | null;
  while ((m = re.exec(match[1] ?? '')) !== null) {
    params[m[1].toLowerCase()] = m[2] !== undefined ? m[2].replace(/\\(.)/g, '$1') : m[3];
  }
  return params;
}
