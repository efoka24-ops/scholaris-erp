// Lecture d'un fichier d'import en CSV « ; » (format du modèle) : CSV transmis tel quel, XLSX converti en CSV dans le
// navigateur (première feuille, valeurs affichées) sans bibliothèque : archive ZIP lue à la main, inflate par DecompressionStream.

export async function fileToCsv(file: File): Promise<string> {
  const buf = new Uint8Array(await file.arrayBuffer());
  const isZip = buf.length > 4 && buf[0] === 0x50 && buf[1] === 0x4b;
  if (!isZip) return new TextDecoder('utf-8').decode(buf);
  return xlsxToCsv(buf);
}

interface ZipEntry { name: string; method: number; offset: number; size: number }

function entries(buf: Uint8Array): ZipEntry[] {
  const view = new DataView(buf.buffer, buf.byteOffset, buf.byteLength);
  let eocd = -1;
  for (let i = buf.length - 22; i >= Math.max(0, buf.length - 65_557); i--) {
    if (view.getUint32(i, true) === 0x06054b50) {
      eocd = i;
      break;
    }
  }
  if (eocd < 0) throw new Error('zip');
  const count = view.getUint16(eocd + 10, true);
  let p = view.getUint32(eocd + 16, true);
  const out: ZipEntry[] = [];
  for (let n = 0; n < count; n++) {
    if (view.getUint32(p, true) !== 0x02014b50) throw new Error('zip');
    const method = view.getUint16(p + 10, true);
    const size = view.getUint32(p + 20, true);
    const nameLen = view.getUint16(p + 28, true);
    const extraLen = view.getUint16(p + 30, true);
    const commentLen = view.getUint16(p + 32, true);
    const local = view.getUint32(p + 42, true);
    const name = new TextDecoder().decode(buf.subarray(p + 46, p + 46 + nameLen));
    const dataStart = local + 30 + view.getUint16(local + 26, true) + view.getUint16(local + 28, true);
    out.push({ name, method, offset: dataStart, size });
    p += 46 + nameLen + extraLen + commentLen;
  }
  return out;
}

async function read(buf: Uint8Array, e: ZipEntry): Promise<string> {
  const raw = buf.slice(e.offset, e.offset + e.size);
  if (e.method === 0) return new TextDecoder().decode(raw);
  const stream = new Blob([raw]).stream().pipeThrough(new DecompressionStream('deflate-raw'));
  return new Response(stream).text();
}

const colIndex = (ref: string): number => {
  let n = 0;
  for (const ch of ref.replace(/\d+/g, '')) n = n * 26 + (ch.charCodeAt(0) - 64);
  return n - 1;
};

export async function xlsxToCsv(buf: Uint8Array): Promise<string> {
  const list = entries(buf);
  const sheet = list.find((e) => /^xl\/worksheets\/sheet1\.xml$/i.test(e.name)) ?? list.find((e) => /^xl\/worksheets\/.+\.xml$/i.test(e.name));
  if (!sheet) throw new Error('xlsx');
  const sharedEntry = list.find((e) => /^xl\/sharedStrings\.xml$/i.test(e.name));
  const parser = new DOMParser();
  const shared: string[] = [];
  if (sharedEntry) {
    const doc = parser.parseFromString(await read(buf, sharedEntry), 'application/xml');
    for (const si of Array.from(doc.getElementsByTagName('si'))) shared.push(Array.from(si.getElementsByTagName('t')).map((t) => t.textContent ?? '').join(''));
  }
  const doc = parser.parseFromString(await read(buf, sheet), 'application/xml');
  const lines: string[] = [];
  for (const row of Array.from(doc.getElementsByTagName('row'))) {
    const cells: string[] = [];
    for (const c of Array.from(row.getElementsByTagName('c'))) {
      const idx = colIndex(c.getAttribute('r') ?? '');
      const type = c.getAttribute('t');
      let value = c.getElementsByTagName('v')[0]?.textContent ?? '';
      if (type === 's') value = shared[Number(value)] ?? '';
      else if (type === 'inlineStr') value = Array.from(c.getElementsByTagName('t')).map((t) => t.textContent ?? '').join('');
      cells[idx >= 0 ? idx : cells.length] = value.replace(/[;\r\n]/g, ' ');
    }
    lines.push(Array.from(cells, (v) => v ?? '').join(';'));
  }
  return `${lines.join('\n')}\n`;
}

/** Déclenche le téléchargement d'un texte (modèle CSV). */
export function downloadText(content: string, filename: string, type = 'text/csv;charset=utf-8'): void {
  const url = URL.createObjectURL(new Blob([content], { type }));
  const a = document.createElement('a');
  a.href = url;
  a.download = filename;
  a.click();
  URL.revokeObjectURL(url);
}
