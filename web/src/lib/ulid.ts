// Identifiant ULID généré par l'appareil (client_uuid) : 48 bits de temps + 80 bits aléatoires, base 32 de Crockford.
const ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

export function ulid(now: number = Date.now(), random: (bytes: Uint8Array<ArrayBuffer>) => Uint8Array = (b) => crypto.getRandomValues(b)): string {
  let time = '';
  let t = now;
  for (let i = 0; i < 10; i++) {
    time = ALPHABET.charAt(t % 32) + time;
    t = Math.floor(t / 32);
  }
  const bytes = random(new Uint8Array(16));
  let rand = '';
  for (let i = 0; i < 16; i++) {
    rand += ALPHABET.charAt((bytes[i] ?? 0) % 32);
  }
  return time + rand;
}

export const ULID_PATTERN = /^[0-9A-HJKMNP-TV-Z]{26}$/;
