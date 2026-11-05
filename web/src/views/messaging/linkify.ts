// Texte d'un message rendu sans v-html : segments de texte brut et liens http(s) seulement (aucun autre schéma).
export interface Segment {
  text: string;
  href?: string;
}

const URL_PATTERN = /\bhttps?:\/\/[^\s<>"']+/gi;

export function linkify(body: string): Segment[] {
  const out: Segment[] = [];
  let last = 0;
  for (const match of body.matchAll(URL_PATTERN)) {
    const index = match.index ?? 0;
    // Ponctuation finale hors du lien (« voir https://exemple.cm. »).
    const url = match[0].replace(/[.,;:!?)\]]+$/, '');
    if (index > last) out.push({ text: body.slice(last, index) });
    out.push(safeUrl(url) ? { text: url, href: url } : { text: url });
    last = index + url.length;
  }
  if (last < body.length) out.push({ text: body.slice(last) });
  return out;
}

function safeUrl(raw: string): boolean {
  try {
    const url = new URL(raw);
    return url.protocol === 'https:' || url.protocol === 'http:';
  } catch {
    return false;
  }
}
