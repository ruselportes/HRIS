const fs = require('fs');
const path = require('path');
const puppeteer = require(path.resolve(process.env.APPDATA, 'npm-cache/_npx/668c188756b835f3/node_modules/puppeteer-core'));

const DIAGRAMS_DIR = 'c:\\capstone\\diagrams';
const OUT_DIR = 'C:\\Users\\Melva Portes\\.gemini\\antigravity-ide\\brain\\9c74c11e-65ae-4b52-86d8-3c43b501c3bb';

const CHECK_FILES = [
  { svg: 'use_case_diagram.svg', out: 'verify_master.png',  label: 'Master Map' },
  { svg: 'use_case_01.svg',      out: 'verify_uc01.png',    label: 'UC-01 (4 actors)' },
  { svg: 'use_case_02.svg',      out: 'verify_uc02.png',    label: 'UC-02' },
  { svg: 'use_case_03.svg',      out: 'verify_uc03.png',    label: 'UC-03' },
  { svg: 'use_case_14.svg',      out: 'verify_uc14.png',    label: 'UC-14 (2 actors)' },
];

(async () => {
  const chromePath = 'C:\\Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe';
  const browser = await puppeteer.launch({
    executablePath: chromePath,
    headless: 'new',
    args: ['--no-sandbox', '--disable-setuid-sandbox']
  });

  for (const { svg, out, label } of CHECK_FILES) {
    const page = await browser.newPage();
    await page.setViewport({ width: 1300, height: 900 });
    const svgContent = fs.readFileSync(path.join(DIAGRAMS_DIR, svg), 'utf-8');
    const html = `<!DOCTYPE html><html><head><style>body{margin:0;padding:8px;background:#fff;}</style></head><body>${svgContent}</body></html>`;
    try {
      await page.setContent(html, { waitUntil: 'domcontentloaded', timeout: 10000 });
    } catch(e) {
      // timeout fine – content is already loaded
    }
    await new Promise(r => setTimeout(r, 800));
    const outPath = path.join(OUT_DIR, out);
    await page.screenshot({ path: outPath, fullPage: true });
    await page.close();
    console.log(`✅ ${label} → ${out}`);
  }

  await browser.close();
  console.log('DONE.');
})().catch(err => { console.error('FATAL:', err); process.exit(1); });
