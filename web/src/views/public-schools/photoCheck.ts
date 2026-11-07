// Contrôle d'une photo avant envoi (type, signature du fichier et taille). Le serveur refuse de toute façon ce qui n'est pas une
// image JPEG ou PNG dans la limite de son magasin de médias : ce contrôle évite seulement un envoi voué à l'échec.

/** Taille maximale d'une photo : celle du magasin de médias du serveur (2 Mo par défaut). */
export const MAX_PHOTO_BYTES = 2 * 1024 * 1024;
export const PHOTO_TYPES = ['image/jpeg', 'image/png'];

export interface PhotoCheck {
  ok: boolean;
  problem: '' | 'type' | 'size' | 'empty';
}

/** Premiers octets d'un fichier (FileReader : disponible partout, y compris sur les anciens navigateurs mobiles). */
function readHead(f: Blob, length = 8): Promise<Uint8Array> {
  return new Promise((resolve, reject) => {
    const reader = new FileReader();
    reader.onerror = () => reject(reader.error ?? new Error('read'));
    reader.onload = () => resolve(new Uint8Array(reader.result as ArrayBuffer));
    reader.readAsArrayBuffer(f.slice(0, length));
  });
}

/** Le type annoncé par le navigateur ne suffit pas : on vérifie aussi les premiers octets du fichier. */
async function looksLikeImage(f: Blob & { type: string }): Promise<boolean> {
  const head = await readHead(f);
  const jpeg = head[0] === 0xff && head[1] === 0xd8 && head[2] === 0xff;
  const png = head[0] === 0x89 && head[1] === 0x50 && head[2] === 0x4e && head[3] === 0x47;
  return (f.type === 'image/jpeg' && jpeg) || (f.type === 'image/png' && png);
}

export async function checkPhoto(f: File): Promise<PhotoCheck> {
  if (f.size === 0) return { ok: false, problem: 'empty' };
  if (!PHOTO_TYPES.includes(f.type) || !(await looksLikeImage(f))) return { ok: false, problem: 'type' };
  if (f.size > MAX_PHOTO_BYTES) return { ok: false, problem: 'size' };
  return { ok: true, problem: '' };
}
