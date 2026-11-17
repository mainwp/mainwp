import crypto from 'node:crypto';

export function challengeFor(verifier: string): string {
  return crypto.createHash('sha256').update(verifier, 'ascii').digest('base64url');
}

/** 32 random bytes give a 43-character verifier, the minimum RFC 7636 4.1 allows. */
export function pkcePair(): { verifier: string; challenge: string } {
  const verifier = crypto.randomBytes(32).toString('base64url');
  return { verifier, challenge: challengeFor(verifier) };
}
