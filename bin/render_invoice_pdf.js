'use strict';

const fs = require('node:fs');
const qrcode = require('qrcode-generator');
const { chromium } = require('playwright-core');

function findBrowser() {
  const configured = process.env.INVOICE_PDF_BROWSER;
  const candidates = configured
    ? [configured]
    : [
        'C:\\Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe',
        'C:\\Program Files\\Microsoft\\Edge\\Application\\msedge.exe',
        'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe',
        'C:\\Program Files (x86)\\Google\\Chrome\\Application\\chrome.exe',
        '/usr/bin/chromium',
        '/usr/bin/chromium-browser',
        '/usr/bin/google-chrome',
        '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
      ];

  for (const candidate of candidates) {
    if (fs.existsSync(candidate)) return candidate;
  }
  throw new Error('No supported Chromium browser found; set INVOICE_PDF_BROWSER to its executable path.');
}

async function main() {
  const inputPath = process.argv[2];
  const outputPath = process.argv[3];
  if (!inputPath || !outputPath) throw new Error('Input and output paths are required.');

  const input = JSON.parse(fs.readFileSync(inputPath, 'utf8'));
  let html = input.html;
  if (typeof input.qrUrl === 'string' && input.qrUrl !== '') {
    const qr = qrcode(0, 'M');
    qr.addData(input.qrUrl);
    qr.make();
    const size = qr.getModuleCount();
    let pathData = '';
    for (let row = 0; row < size; row += 1) {
      for (let column = 0; column < size; column += 1) {
        if (qr.isDark(row, column)) pathData += `M${column} ${row}h1v1h-1z`;
      }
    }
    const svg = `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ${size} ${size}" shape-rendering="crispEdges" role="img" aria-label="QR code for this invoice"><path d="${pathData}" fill="#141d38"/></svg>`;
    if (!html.includes('<!--SERVER_INVOICE_QR-->')) {
      throw new Error('Invoice QR placeholder is missing from the PDF document.');
    }
    html = html.replace('<!--SERVER_INVOICE_QR-->', svg);
  }

  let browser;
  let context;
  try {
    browser = await chromium.launch({
      executablePath: findBrowser(),
      headless: true,
      timeout: 30000,
      args: ['--disable-dev-shm-usage', '--disable-gpu', '--disable-software-rasterizer'],
    });
    context = await browser.newContext({
      viewport: { width: 1200, height: 1600 },
      deviceScaleFactor: 1,
      colorScheme: 'light',
    });
    const page = await context.newPage();
    await page.setContent(html, { waitUntil: 'load', timeout: 30000 });
    await page.emulateMedia({ media: 'print', colorScheme: 'light' });
    await page.evaluate(async () => {
      await document.fonts.ready;
      await Promise.all(Array.from(document.images, image => image.decode()));
    });
    if (input.qrUrl) {
      await page.waitForSelector('#qrCode svg', { state: 'attached', timeout: 5000 });
    }
    const pdf = await page.pdf({
      format: 'A4',
      printBackground: true,
      preferCSSPageSize: true,
      displayHeaderFooter: false,
      margin: { top: '0', right: '0', bottom: '0', left: '0' },
    });
    fs.writeFileSync(outputPath, pdf);
  } finally {
    if (context) await context.close();
    if (browser) await browser.close();
  }
}

main().catch(error => {
  process.stderr.write(`${error.stack || error.message}\n`);
  process.exitCode = 1;
});
