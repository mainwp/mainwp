/** Literal titles of top-level `test(...)` calls; `test.describe` and `test.step` are not tests. */
export function specTitles(source: string): string[] {
  const titles: string[] = [];
  const re = /^test\(\s*(['"])((?:(?!\1).)*)\1/gm;
  let m: RegExpExecArray | null;
  while ((m = re.exec(source)) !== null) {
    titles.push(m[2]);
  }
  return titles;
}
