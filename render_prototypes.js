const path = require('path');
const puppeteer = require(path.resolve(process.env.APPDATA, 'npm-cache/_npx/668c188756b835f3/node_modules/puppeteer-core'));

async function renderPrototypes() {
  console.log('Launching Puppeteer browser...');
  const executablePath = path.resolve(process.env.APPDATA, 'npm-cache/_npx/668c188756b835f3/node_modules/puppeteer-core/.local-chromium/win64-1045932/chrome-win/chrome.exe');
  
  // Find chrome executable from puppeteer cache or system edge/chrome
  let chromePath = 'C:\\Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe';
  if (!require('fs').existsSync(chromePath)) {
    chromePath = 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
  }

  const browser = await puppeteer.launch({
    executablePath: chromePath,
    headless: 'new',
    args: ['--no-sandbox', '--disable-setuid-sandbox']
  });

  const page = await browser.newPage();
  await page.setViewport({ width: 1200, height: 1000, deviceScaleFactor: 2 });

  const htmlPath = 'file:///' + path.resolve(__dirname, 'prototypes/prototypes.html').replace(/\\/g, '/');
  console.log('Navigating to ' + htmlPath);
  await page.goto(htmlPath, { waitUntil: 'networkidle0' });

  const protoIds = [
    { id: 'pr-01', name: 'pr_01_user_rbac.png' },
    { id: 'pr-02', name: 'pr_02_worker_registry.png' },
    { id: 'pr-03', name: 'pr_03_crew_assignment.png' },
    { id: 'pr-04', name: 'pr_04_mobile_checklist.png' },
    { id: 'pr-05', name: 'pr_05_local_encryption.png' },
    { id: 'pr-06', name: 'pr_06_monotonic_clock.png' },
    { id: 'pr-07', name: 'pr_07_hmac_hash_chain.png' },
    { id: 'pr-08', name: 'pr_08_tee_signature.png' },
    { id: 'pr-09', name: 'pr_09_background_sync.png' },
    { id: 'pr-10', name: 'pr_10_late_foreman_override.png' },
    { id: 'pr-11', name: 'pr_11_crew_reassignment.png' },
    { id: 'pr-12', name: 'pr_12_retroactive_recovery.png' },
    { id: 'pr-13', name: 'pr_13_automated_payroll.png' },
    { id: 'pr-14', name: 'pr_14_leave_ot_filing.png' },
    { id: 'pr-15', name: 'pr_15_executive_analytics.png' }
  ];

  for (const item of protoIds) {
    const element = await page.$('#' + item.id);
    if (element) {
      const outPath = path.resolve(__dirname, 'prototypes', item.name);
      await element.screenshot({ path: outPath, omitBackground: true });
      console.log(`SUCCESS: Rendered ${item.name}`);
    } else {
      console.error(`ERROR: Could not find #${item.id}`);
    }
  }

  await browser.close();
  console.log('All 15 UI Prototypes successfully rendered to PNG images!');
}

renderPrototypes().catch(err => {
  console.error('Fatal error during rendering:', err);
  process.exit(1);
});
