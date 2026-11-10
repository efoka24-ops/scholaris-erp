#!/usr/bin/env node
/**
 * Assemble un guide d'utilisation (dossier de chapitres Markdown NN-*.md + images/) en un PDF A4 imprimable, par l'impression
 * Chromium de Playwright : page de titre, sommaire avec numéros de page, chapitres sur une nouvelle page, pied de page
 * « titre · page N / M », images lisibles (jamais coupées), liens entre chapitres internes au PDF.
 *
 * Script partagé entre les guides (primaire, secondaire…) : aucune valeur propre à un guide ici, tout passe par les options.
 *
 *   cd web/e2e
 *   node guide/build-pdf.mjs --src ../../specs/005-application-secondaire/guide \
 *        --out ../../specs/005-application-secondaire/guide/Guide-SCHOLARIS-Secondaire.pdf \
 *        --title "Guide d'utilisation" --subtitle "Application Secondaire" [--edition "Novembre 2026"] [--pages-png <dossier>]
 *
 * --pages-png : exporte aussi chaque page du PDF en PNG (contrôle visuel), si pdfjs-dist est installé quelque part
 *               (option --pdfjs <dossier node_modules/pdfjs-dist>) ; sinon ignoré.
 *
 * Markdown pris en charge : titres #…####, paragraphes, listes à puces et numérotées (imbriquées par indentation), tableaux,
 * blocs ``` , citations >, ligne ---, **gras**, *italique*, `code`, liens, images (seules sur leur ligne : figure + légende).
 */
import { chromium } from '@playwright/test';
import { existsSync, mkdtempSync, readdirSync, readFileSync, rmSync, writeFileSync, mkdirSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { basename, dirname, join, resolve } from 'node:path';
import { pathToFileURL } from 'node:url';

// ------------------------------------------------------------------------------------------------------------- options
function parseArgs(argv) {
  const out = {};
  for (let i = 0; i < argv.length; i++) {
    const a = argv[i];
    if (!a.startsWith('--')) continue;
    const key = a.slice(2);
    const next = argv[i + 1];
    if (next === undefined || next.startsWith('--')) out[key] = true;
    else {
      out[key] = next;
      i++;
    }
  }
  return out;
}

const args = parseArgs(process.argv.slice(2));
if (!args.src || !args.out) {
  console.error('Usage : node guide/build-pdf.mjs --src <dossier du guide> --out <fichier.pdf> [--title ...] [--subtitle ...] [--edition ...] [--pages-png <dossier>]');
  process.exit(2);
}
const SRC = resolve(String(args.src));
const OUT = resolve(String(args.out));
const TITLE = String(args.title ?? "Guide d'utilisation");
const SUBTITLE = String(args.subtitle ?? '');
const EDITION = String(args.edition ?? new Intl.DateTimeFormat('fr-FR', { month: 'long', year: 'numeric' }).format(new Date()));
const FOOTER = String(args.footer ?? `SCHOLARIS · ${SUBTITLE || TITLE}`);

// ------------------------------------------------------------------------------------------------------------ markdown
const esc = (s) => s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');

export function slug(s) {
  return s
    .normalize('NFD')
    .replace(/[̀-ͯ]/g, '')
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/^-|-$/g, '');
}

/** Ancre d'un chapitre à partir de son nom de fichier. */
const chapterId = (file) => 'ch-' + slug(basename(file, '.md'));

function makeInline(ctx) {
  return function inline(text) {
    const codes = [];
    let s = text.replace(/`([^`]+)`/g, (_, c) => {
      codes.push(`<code>${esc(c)}</code>`);
      return `\u0000${codes.length - 1}\u0000`;
    });
    s = esc(s);
    s = s.replace(/!\[([^\]]*)\]\(([^)\s]+)\)/g, (_, alt, src) => `<img src="${ctx.img(src)}" alt="${alt}">`);
    s = s.replace(/\[([^\]]+)\]\(([^)\s]+)\)/g, (_, label, href) => `<a href="${ctx.link(href.replace(/&amp;/g, '&'))}">${label}</a>`);
    s = s.replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>');
    s = s.replace(/(^|[^*\w])\*([^*\s][^*]*?)\*(?!\w)/g, '$1<em>$2</em>');
    s = s.replace(/\u0000(\d+)\u0000/g, (_, i) => codes[Number(i)]);
    return s;
  };
}

/** Convertit un chapitre Markdown en HTML. */
export function markdownToHtml(md, ctx) {
  const inline = makeInline(ctx);
  const lines = md.replace(/\r\n/g, '\n').split('\n');
  const html = [];
  let i = 0;
  let firstH1 = true;
  const isBlank = (l) => /^\s*$/.test(l);
  const listRe = /^(\s*)([-*+]|\d+[.)])\s+(.*)$/;
  const startsBlock = (l) => /^(#{1,6}\s|```|>|\|)/.test(l) || /^\s*(---|\*\*\*)\s*$/.test(l) || listRe.test(l);

  while (i < lines.length) {
    const line = lines[i];
    if (isBlank(line)) {
      i++;
      continue;
    }
    // Bloc de code.
    if (line.startsWith('```')) {
      const buf = [];
      i++;
      while (i < lines.length && !lines[i].startsWith('```')) buf.push(lines[i++]);
      i++;
      html.push(`<pre>${esc(buf.join('\n'))}</pre>`);
      continue;
    }
    // Titre.
    const h = /^(#{1,6})\s+(.*)$/.exec(line);
    if (h) {
      const level = h[1].length;
      const text = h[2].trim();
      const id = level === 1 && firstH1 ? ctx.chapter : `${ctx.chapter}-${slug(text)}`;
      if (level === 1) firstH1 = false;
      html.push(`<h${level} id="${id}">${inline(text)}</h${level}>`);
      i++;
      continue;
    }
    // Ligne horizontale.
    if (/^\s*(---|\*\*\*)\s*$/.test(line)) {
      html.push('<hr>');
      i++;
      continue;
    }
    // Citation.
    if (line.startsWith('>')) {
      const buf = [];
      while (i < lines.length && lines[i].startsWith('>')) buf.push(lines[i++].replace(/^>\s?/, ''));
      html.push(`<blockquote>${markdownToHtml(buf.join('\n'), { ...ctx, chapter: ctx.chapter + '-q' })}</blockquote>`);
      continue;
    }
    // Tableau.
    if (line.trim().startsWith('|') && i + 1 < lines.length && /^\s*\|?\s*:?-{2,}/.test(lines[i + 1])) {
      const cells = (l) => l.trim().replace(/^\|/, '').replace(/\|\s*$/, '').split(/(?<!\\)\|/).map((c) => c.trim().replace(/\\\|/g, '|'));
      const head = cells(line);
      i += 2;
      const rows = [];
      while (i < lines.length && lines[i].trim().startsWith('|')) rows.push(cells(lines[i++]));
      html.push(
        `<table><thead><tr>${head.map((c) => `<th>${inline(c)}</th>`).join('')}</tr></thead><tbody>` +
          rows.map((r) => `<tr>${r.map((c) => `<td>${inline(c)}</td>`).join('')}</tr>`).join('') +
          '</tbody></table>',
      );
      continue;
    }
    // Liste (imbriquée par indentation).
    if (listRe.test(line)) {
      const stack = []; // { indent, tag }
      const out = [];
      let liOpen = false;
      while (i < lines.length) {
        const l = lines[i];
        if (isBlank(l)) {
          // Une ligne vide ne termine la liste que si la suivante n'en fait pas partie.
          const nx = lines[i + 1];
          if (nx !== undefined && (listRe.test(nx) || /^\s{2,}\S/.test(nx))) {
            i++;
            continue;
          }
          break;
        }
        const m = listRe.exec(l);
        if (m) {
          const indent = m[1].length;
          const tag = /\d/.test(m[2]) ? 'ol' : 'ul';
          if (!stack.length || indent > stack[stack.length - 1].indent) {
            stack.push({ indent, tag });
            const start = tag === 'ol' && Number.parseInt(m[2], 10) !== 1 ? ` start="${Number.parseInt(m[2], 10)}"` : '';
            out.push(`<${tag}${start}>`);
          } else {
            while (stack.length > 1 && indent < stack[stack.length - 1].indent) {
              out.push(`</li></${stack.pop().tag}>`);
            }
            out.push('</li>');
          }
          out.push(`<li>${inline(m[3])}`);
          liOpen = true;
          i++;
          continue;
        }
        if (/^\s+\S/.test(l) && liOpen) {
          // Suite du point précédent (ligne indentée), ou image indentée.
          const t = l.trim();
          const img = /^!\[([^\]]*)\]\(([^)\s]+)\)$/.exec(t);
          out.push(img ? `<figure><img src="${ctx.img(img[2])}" alt="${esc(img[1])}">${img[1] ? `<figcaption>${inline(img[1])}</figcaption>` : ''}</figure>` : ' ' + inline(t));
          i++;
          continue;
        }
        if (startsBlock(l)) break;
        // Ligne non indentée collée à la liste : suite paresseuse du point.
        out.push(' ' + inline(l.trim()));
        i++;
      }
      while (stack.length) out.push(`</li></${stack.pop().tag}>`);
      html.push(out.join(''));
      continue;
    }
    // Image seule : figure.
    const img = /^!\[([^\]]*)\]\(([^)\s]+)\)\s*$/.exec(line.trim());
    if (img) {
      html.push(`<figure><img src="${ctx.img(img[2])}" alt="${esc(img[1])}">${img[1] ? `<figcaption>${inline(img[1])}</figcaption>` : ''}</figure>`);
      i++;
      continue;
    }
    // Paragraphe.
    const buf = [];
    while (i < lines.length && !isBlank(lines[i]) && !(buf.length && startsBlock(lines[i]))) buf.push(lines[i++]);
    html.push(`<p>${inline(buf.join(' '))}</p>`);
  }
  return html.join('\n');
}

// ------------------------------------------------------------------------------------------------------------- styles
const CSS = `
@page { size: A4; margin: 16mm 15mm 18mm 15mm; }
* { box-sizing: border-box; }
html { font-family: "Segoe UI", "Helvetica Neue", Arial, sans-serif; font-size: 10.5pt; color: #1d2433; line-height: 1.45; }
body { margin: 0; }
h1, h2, h3, h4 { color: #0f3d68; line-height: 1.2; break-after: avoid; }
h1 { font-size: 21pt; margin: 0 0 6mm; padding-bottom: 3mm; border-bottom: 2px solid #0f3d68; }
h2 { font-size: 14pt; margin: 7mm 0 2.5mm; }
h3 { font-size: 11.5pt; margin: 5mm 0 2mm; }
h4 { font-size: 10.5pt; margin: 4mm 0 1.5mm; }
p { margin: 0 0 2.5mm; orphans: 3; widows: 3; }
ul, ol { margin: 0 0 2.5mm; padding-left: 6mm; }
li { margin: 0.6mm 0; }
code { font-family: Consolas, "Courier New", monospace; font-size: 9pt; background: #eef2f7; padding: 0 1mm; border-radius: 1mm; }
pre { font-family: Consolas, "Courier New", monospace; font-size: 8.8pt; background: #f3f6fa; border: 1px solid #d5dde8; padding: 2.5mm; white-space: pre-wrap; break-inside: avoid; }
blockquote { margin: 0 0 3mm; padding: 2mm 3mm; border-left: 3px solid #e0a100; background: #fff8e5; break-inside: avoid; }
blockquote p:last-child { margin-bottom: 0; }
table { border-collapse: collapse; width: 100%; margin: 1mm 0 3.5mm; font-size: 9.3pt; }
thead { display: table-header-group; }
tr { break-inside: avoid; }
th, td { border: 1px solid #c9d3df; padding: 1.2mm 2mm; text-align: left; vertical-align: top; }
th { background: #e8eef6; color: #0f3d68; }
a { color: #0b5cad; text-decoration: none; }
hr { border: 0; border-top: 1px solid #c9d3df; margin: 4mm 0; }
figure { margin: 2mm 0 4mm; text-align: center; break-inside: avoid; }
figure img { max-width: 100%; max-height: 228mm; width: auto; height: auto; border: 1px solid #c9d3df; box-shadow: 0 0.5mm 1.5mm rgba(0,0,0,.12); }
figcaption { font-size: 8.8pt; color: #5a6475; margin-top: 1.2mm; font-style: italic; }
p > img { max-width: 100%; }
.chapter { break-before: page; }
.cover { height: 255mm; display: flex; flex-direction: column; justify-content: center; padding: 0 8mm; break-after: page;
  background: linear-gradient(160deg, #0f3d68 0%, #145a96 60%, #1f78c1 100%); color: #fff; margin: -1mm; border-radius: 2mm; }
.cover .brand { font-size: 13pt; letter-spacing: 3px; font-weight: 700; opacity: .9; }
.cover .logo { display: inline-flex; width: 16mm; height: 16mm; border-radius: 3mm; background: #fff; color: #0f3d68; font-weight: 800; font-size: 22pt; align-items: center; justify-content: center; margin-bottom: 8mm; }
.cover h1.ct { color: #fff; border: 0; font-size: 30pt; margin: 6mm 0 3mm; padding: 0; }
.cover .sub { font-size: 18pt; font-weight: 600; margin-bottom: 14mm; }
.cover .meta { font-size: 10.5pt; opacity: .9; line-height: 1.6; }
.toc h1 { font-size: 18pt; }
.toc ol { list-style: none; padding: 0; margin: 0; }
.toc li { display: flex; align-items: baseline; margin: 0; padding: 1.6mm 0; border-bottom: 1px dotted #b8c4d3; font-size: 11pt; }
.toc li .t { flex: 1; }
.toc li .n { font-weight: 600; color: #0f3d68; min-width: 10mm; text-align: right; }
.toc li.sub { padding: 0.6mm 0 0.6mm 7mm; font-size: 9.5pt; color: #3d4757; border-bottom: 0; }
`;

// ------------------------------------------------------------------------------------------------------------ assemblage
const files = readdirSync(SRC)
  .filter((f) => /^\d{2}-.*\.md$/i.test(f))
  .sort();
if (!files.length) {
  console.error(`Aucun chapitre NN-*.md dans ${SRC}`);
  process.exit(1);
}
const known = new Set(files);

const chapters = files.map((file) => {
  const md = readFileSync(join(SRC, file), 'utf8');
  const ctx = {
    chapter: chapterId(file),
    img: (src) => (/^(https?:|data:|file:)/.test(src) ? src : pathToFileURL(resolve(SRC, decodeURIComponent(src))).href),
    link: (href) => {
      const [f, hash] = href.split('#');
      if (f && known.has(basename(f)) && !/^https?:/.test(href)) return '#' + (hash ? `${chapterId(f)}-${hash}` : chapterId(f));
      if (!f && hash) return `#${chapterId(file)}-${hash}`;
      return esc(href);
    },
  };
  const title = (/^#\s+(.+)$/m.exec(md)?.[1] ?? basename(file, '.md')).trim();
  const subs = [...md.matchAll(/^##\s+(.+)$/gm)].map((m) => m[1].trim());
  return { file, id: ctx.chapter, title, subs, html: markdownToHtml(md, ctx) };
});

const plain = (s) => esc(s.replace(/\*\*|`/g, ''));
const cover = `<section class="cover"><div><span class="logo">S</span></div><div class="brand">SCHOLARIS</div>
<h1 class="ct">${esc(TITLE)}</h1>${SUBTITLE ? `<div class="sub">${esc(SUBTITLE)}</div>` : ''}
<div class="meta">Édition : ${esc(EDITION)}<br>https://scholariserp.trugroup.cm<br>Les noms, numéros et montants des exemples sont fictifs.</div></section>`;

function toc(pages) {
  const items = chapters
    .map((c, k) => {
      const n = pages ? String(pages[k]) : '000';
      const subs = args['toc-depth'] === '1' ? '' : c.subs.map((s) => `<li class="sub"><span class="t">${plain(s)}</span></li>`).join('');
      return `<li><a class="t" href="#${c.id}">${plain(c.title)}</a><span class="n">${n}</span></li>${subs}`;
    })
    .join('');
  return `<section class="toc"><h1>Sommaire</h1><ol>${items}</ol></section>`;
}

const page = (body) => `<!doctype html><html lang="fr"><head><meta charset="utf-8"><title>${esc(TITLE)} ${esc(SUBTITLE)}</title><style>${CSS}</style></head><body>${body}</body></html>`;
const chapterHtml = (c) => `<section class="chapter">${c.html}</section>`;

const work = mkdtempSync(join(tmpdir(), 'scholaris-guide-pdf-'));
const browser = await chromium.launch();
try {
  const ctxB = await browser.newContext();
  const tab = await ctxB.newPage();
  const footerTemplate = `<div style="font-family:Segoe UI,Arial,sans-serif;font-size:7.5pt;color:#6b7686;width:100%;padding:0 15mm;display:flex;justify-content:space-between;"><span>${esc(FOOTER)}</span><span>Page <span class="pageNumber"></span> / <span class="totalPages"></span></span></div>`;
  const pdfOpts = { format: 'A4', printBackground: true, preferCSSPageSize: true, displayHeaderFooter: true, headerTemplate: '<span></span>', footerTemplate };

  const render = async (html, out) => {
    const file = join(work, 'page.html');
    writeFileSync(file, html);
    await tab.goto(pathToFileURL(file).href);
    await tab.waitForLoadState('load');
    await tab.evaluate(() => Promise.all([...document.images].map((im) => (im.complete ? 0 : new Promise((r) => { im.onload = im.onerror = r; })))));
    const broken = await tab.evaluate(() => [...document.images].filter((im) => !im.naturalWidth).map((im) => im.getAttribute('src')));
    const buf = await tab.pdf({ ...pdfOpts, ...(out ? { path: out } : {}) });
    return { buf, broken };
  };
  const countPages = (buf) => (buf.toString('latin1').match(/\/Type\s*\/Page(?![s\w])/g) ?? []).length;

  // 1) Pages de tête (titre + sommaire), puis chaque chapitre seul : chacun commence sur une nouvelle page.
  const front = countPages((await render(page(cover + toc(null)), null)).buf);
  const pages = [];
  let at = front + 1;
  const broken = new Set();
  for (const c of chapters) {
    pages.push(at);
    const r = await render(page(chapterHtml(c).replace('class="chapter"', 'class="chapter first"')), null);
    r.broken.forEach((b) => broken.add(b));
    at += countPages(r.buf);
  }
  // 2) Document complet avec les numéros du sommaire.
  mkdirSync(dirname(OUT), { recursive: true });
  const css1 = '.chapter.first { break-before: auto; }';
  const full = page(cover + toc(pages) + chapters.map(chapterHtml).join('\n')).replace('</style>', css1 + '</style>');
  const { buf } = await render(full, OUT);
  const total = countPages(buf);
  console.log(`PDF : ${OUT}`);
  console.log(`${chapters.length} chapitres, ${total} pages (attendu ${at - 1}).`);
  chapters.forEach((c, k) => console.log(`  p. ${String(pages[k]).padStart(3)}  ${c.title}`));
  if (broken.size) console.warn(`Images introuvables : ${[...broken].join(', ')}`);

  // 3) Contrôle visuel facultatif : pages en PNG par pdf.js.
  if (args['pages-png']) {
    const pdfjsDir = args.pdfjs ? resolve(String(args.pdfjs)) : null;
    const lib = pdfjsDir && [join(pdfjsDir, 'legacy', 'build', 'pdf.min.mjs'), join(pdfjsDir, 'build', 'pdf.min.mjs')].find((p) => existsSync(p));
    if (!lib) console.warn('--pages-png ignoré : indiquez --pdfjs <chemin de node_modules/pdfjs-dist>.');
    else {
      const outDir = resolve(String(args['pages-png']));
      mkdirSync(outDir, { recursive: true });
      const worker = lib.replace('pdf.min.mjs', 'pdf.worker.min.mjs');
      const viewer = join(work, 'viewer.html');
      writeFileSync(viewer, `<!doctype html><html><body style="margin:0;background:#888"><canvas id="c"></canvas><script type="module">
        import * as pdfjs from '${pathToFileURL(lib).href}';
        pdfjs.GlobalWorkerOptions.workerSrc = '${pathToFileURL(worker).href}';
        window.renderPage = async (data, n, scale) => {
          window.__doc ??= await pdfjs.getDocument({ data: Uint8Array.from(atob(data), (ch) => ch.charCodeAt(0)) }).promise;
          const p = await window.__doc.getPage(n); const v = p.getViewport({ scale }); const c = document.getElementById('c');
          c.width = v.width; c.height = v.height; await p.render({ canvasContext: c.getContext('2d'), viewport: v }).promise; return window.__doc.numPages;
        };
        window.ready = true;
      </script></body></html>`);
      const v = await (await browser.newContext({ viewport: { width: 1000, height: 1400 } })).newPage();
      await v.goto(pathToFileURL(viewer).href);
      await v.waitForFunction(() => window.ready === true);
      const data = buf.toString('base64');
      const wanted = String(args['pages-png-list'] ?? '').split(',').filter(Boolean).map(Number);
      const list = wanted.length ? wanted : Array.from({ length: total }, (_, k) => k + 1);
      for (const n of list) {
        await v.evaluate(([d, k]) => window.renderPage(d, k, 1.3), [data, n]);
        await v.locator('#c').screenshot({ path: join(outDir, `page-${String(n).padStart(3, '0')}.png`) });
      }
      console.log(`${list.length} page(s) exportée(s) en PNG dans ${outDir}`);
    }
  }
} finally {
  await browser.close();
  rmSync(work, { recursive: true, force: true });
}
