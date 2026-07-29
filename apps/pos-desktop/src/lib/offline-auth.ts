import type { OfflineCredential } from './types'

function decodeBase64(value: string): Uint8Array {
  const binary = atob(value)
  return Uint8Array.from(binary, (char) => char.charCodeAt(0))
}

function equalBytes(left: Uint8Array, right: Uint8Array): boolean {
  if (left.length !== right.length) return false
  let different = 0
  for (let i = 0; i < left.length; i += 1) different |= left[i] ^ right[i]
  return different === 0
}

export async function verifyOfflinePin(pin: string, credential: OfflineCredential): Promise<boolean> {
  if (!credential || new Date(credential.expires_at).getTime() <= Date.now()) return false
  const key = await crypto.subtle.importKey('raw', new TextEncoder().encode(pin), 'PBKDF2', false, ['deriveBits'])
  const bits = await crypto.subtle.deriveBits({
    name: 'PBKDF2',
    salt: decodeBase64(credential.salt),
    iterations: credential.iterations,
    hash: 'SHA-256',
  }, key, 256)
  return equalBytes(new Uint8Array(bits), decodeBase64(credential.verifier))
}
