// Génère les icônes PNG de l'application (carré noir, barre d'accent, initiale « S » en blocs) sans dépendance.
import { deflateSync } from 'node:zlib';
import { writeFileSync, mkdirSync } from 'node:fs';

function crc32(buf) {
  let c, crc = 0xffffffff;
  for (let n = 0; n < buf.length; n++) {
    c = (crc ^ buf[n]) & 0xff;
    for (let k = 0; k < 8; k++) c = c & 1 ? 0xedb88320 ^ (c >>> 1) : c >>> 1;
    crc = (crc >>> 8) ^ c;
  }
  return (crc ^ 0xffffffff) >>> 0;
}
function chunk(type, data) {
  const len = Buffer.alloc(4); len.writeUInt32BE(data.length);
  const td = Buffer.concat([Buffer.from(type), data]);
  const crc = Buffer.alloc(4); crc.writeUInt32BE(crc32(td));
  return Buffer.concat([len, td, crc]);
}
// Lettre S sur une grille 5x7
const S = ['01110', '10001', '10000', '01110', '00001', '10001', '01110'];
function png(size) {
  const raw = Buffer.alloc((size * 3 + 1) * size);
  const cell = Math.floor(size / 12);
  const x0 = Math.floor((size - cell * 5) / 2), y0 = Math.floor(size * 0.16);
  for (let y = 0; y < size; y++) {
    raw[y * (size * 3 + 1)] = 0;
    for (let x = 0; x < size; x++) {
      let rgb = [0, 0, 0];
      if (y >= size * 0.78 && y < size * 0.88) rgb = [0xf2, 0x6b, 0x1d];
      const gx = Math.floor((x - x0) / cell), gy = Math.floor((y - y0) / cell);
      if (gx >= 0 && gx < 5 && gy >= 0 && gy < 7 && S[gy][gx] === '1') rgb = [255, 255, 255];
      const o = y * (size * 3 + 1) + 1 + x * 3;
      raw[o] = rgb[0]; raw[o + 1] = rgb[1]; raw[o + 2] = rgb[2];
    }
  }
  const ihdr = Buffer.alloc(13); ihdr.writeUInt32BE(size, 0); ihdr.writeUInt32BE(size, 4); ihdr[8] = 8; ihdr[9] = 2;
  return Buffer.concat([Buffer.from([137, 80, 78, 71, 13, 10, 26, 10]), chunk('IHDR', ihdr), chunk('IDAT', deflateSync(raw)), chunk('IEND', Buffer.alloc(0))]);
}
mkdirSync('public/icons', { recursive: true });
writeFileSync('public/icons/icon-192.png', png(192));
writeFileSync('public/icons/icon-512.png', png(512));
