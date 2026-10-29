const { chromium } = require('playwright');
const fs = require('fs');
const RAW_DIR = require('path').resolve(__dirname, '../../docs/screenshots/raw') + '/';
const img = (f) => 'data:image/png;base64,' + fs.readFileSync(RAW_DIR + f).toString('base64');
const OUT = require('path').resolve(__dirname, '../../docs/screenshots') + '/';
const page = (imgs, captions, title, sub) => `<!doctype html><html><head><style>
  body{margin:0;width:1720px;height:1060px;font-family:Inter,-apple-system,"Segoe UI",sans-serif;
    background:radial-gradient(1200px 700px at 50% 0%,#fff8d6 0%,#f3f2ee 55%,#ecebe6 100%);display:flex;flex-direction:column;align-items:center}
  h1{margin:56px 0 6px;font-size:44px;letter-spacing:-.03em;font-weight:650;color:#17181b}
  p.sub{margin:0 0 38px;font-size:21px;color:#6e7076}
  .row{display:flex;gap:56px;align-items:flex-start}
  figure{margin:0;display:flex;flex-direction:column;align-items:center;gap:18px}
  .phone{width:390px;height:780px;border-radius:52px;padding:12px;background:#17181b;box-shadow:0 30px 60px rgba(0,0,0,.18),0 0 0 1px rgba(0,0,0,.06)}
  .phone img{width:100%;height:100%;object-fit:cover;object-position:top;border-radius:41px;display:block}
  figure:nth-child(2){margin-top:-18px}
  figcaption{font-size:19px;color:#3b3d42;font-weight:500}
</style></head><body><h1>${title}</h1><p class="sub">${sub}</p><div class="row">${imgs.map((src,i)=>`<figure><div class="phone"><img src="${img(src)}"></div><figcaption>${captions[i]}</figcaption></figure>`).join('')}</div></body></html>`;
(async () => {
  const browser = await chromium.launch();
  const ctx = await browser.newContext({ viewport: { width: 1720, height: 1060 }, deviceScaleFactor: 1.5 });
  const p = await ctx.newPage();
  await p.setContent(page(['compose-light.png','board-dark.png','queue-light.png'], ['Compose with live preview','One fair queue','Everyone takes turns'], 'Gumbel for Vestaboard', 'Share one Vestaboard Note with the whole family – fair, automated, connected.'));
  await p.waitForTimeout(500);
  await p.screenshot({ path: OUT + 'hero.png' });
  await p.setContent(page(['integrations-light.png','rules-dark.png','automation-light.png'], ['Test mode shows the payload','Conditional templates','Alarms, clock, weather & more'], 'Automations & integrations', 'Webhooks, JSON/RSS, NFC – tap a field, build a template, done.'));
  await p.waitForTimeout(500);
  await p.screenshot({ path: OUT + 'integrations.png' });
  await browser.close();
})();
