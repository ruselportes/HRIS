const fs = require('fs');
const path = require('path');
const puppeteer = require(path.resolve(process.env.APPDATA, 'npm-cache/_npx/668c188756b835f3/node_modules/puppeteer-core'));

async function renderSvgs() {
  console.log('Launching Puppeteer browser to render textbook UML SVGs to PNG...');
  let chromePath = 'C:\\Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe';
  if (!fs.existsSync(chromePath)) {
    chromePath = 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
  }

  const browser = await puppeteer.launch({
    executablePath: chromePath,
    headless: 'new',
    args: ['--no-sandbox', '--disable-setuid-sandbox']
  });

  const page = await browser.newPage();
  const diagramsDir = path.resolve(__dirname, 'diagrams');

  const files = fs.readdirSync(diagramsDir).filter(f => f.endsWith('.svg') && f.startsWith('use_case'));

  for (const file of files) {
    const svgPath = 'file:///' + path.join(diagramsDir, file).replace(/\\/g, '/');
    const pngName = file.replace('.svg', '.png');
    const pngPath = path.join(diagramsDir, pngName);

    console.log(`Rendering ${file} -> ${pngName}...`);
    await page.goto(svgPath, { waitUntil: 'networkidle0' });
    const svgElement = await page.$('svg');
    if (svgElement) {
      await svgElement.screenshot({ path: pngPath, omitBackground: false });
      console.log(`✅ SUCCESS: Rendered ${pngName}`);
    } else {
      console.error(`❌ ERROR: Could not find SVG in ${file}`);
    }
  }

  await browser.close();
  console.log('All textbook UML Use Case PNG diagrams rendered successfully!');
}

renderSvgs().catch(err => {
  console.error('Fatal error rendering SVGs:', err);
  process.exit(1);
});
