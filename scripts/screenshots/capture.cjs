const { chromium } = require('playwright');
const OUT = '' + require('path').resolve(__dirname, '../../docs/screenshots/raw') + '';
const BASE = 'http://127.0.0.1:8777/';
async function login(page, name, secret) {
  await page.goto(BASE + 'login.php');
  await page.fill('input[name=name]', name);
  await page.fill('input[name=secret]', secret);
  await Promise.all([page.waitForNavigation(), page.press('input[name=secret]', 'Enter')]);
}
(async () => {
  const browser = await chromium.launch();
  for (const scheme of ['light', 'dark']) {
    const ctx = await browser.newContext({ viewport: { width: 390, height: 844 }, deviceScaleFactor: 3, colorScheme: scheme });
    const page = await ctx.newPage();
    await login(page, 'Alex', 'demo-password');
    // Compose mit Text
    await page.goto(BASE + 'index.php');
    await page.fill('textarea[name=text]', 'SOCCER AT 5\nDON\'T FORGET\nYOUR BOOTS {green}');
    await page.waitForTimeout(900);
    await page.screenshot({ path: `${OUT}/compose-${scheme}.png` });
    // Board
    await page.goto(BASE + 'queue.php');
    await page.waitForTimeout(300);
    await page.screenshot({ path: `${OUT}/board-${scheme}.png` });
    await page.evaluate(() => { const h = [...document.querySelectorAll('section > h2')].find(e => /Queue/.test(e.textContent)); h.scrollIntoView({ block: 'start' }); window.scrollBy(0, -70); });
    await page.waitForTimeout(300);
    await page.screenshot({ path: `${OUT}/queue-${scheme}.png` });
    // Automation (Wetter)
    await page.goto(BASE + 'automation.php#weather');
    await page.goto(BASE + 'automation.php#wetter');
    await page.waitForTimeout(400);
    await page.screenshot({ path: `${OUT}/automation-${scheme}.png` });
    // Integrationen: Webhook offen, Payload + Vorlagen
    await page.goto(BASE + 'webhooks.php');
    await page.evaluate(() => { const h = document.querySelector('#webhooks details.hook'); h.open = true; });
    await page.waitForTimeout(1200);
    await page.evaluate(() => { document.querySelector('#webhooks .payload').scrollIntoView({ block: 'start' }); window.scrollBy(0, -70); });
    await page.waitForTimeout(300);
    await page.screenshot({ path: `${OUT}/integrations-${scheme}.png` });
    await page.evaluate(() => { document.querySelector('#webhooks .rule').scrollIntoView({ block: 'start' }); window.scrollBy(0, -90); });
    await page.waitForTimeout(300);
    await page.screenshot({ path: `${OUT}/rules-${scheme}.png` });
    // History mit Antwort
    await page.goto(BASE + 'history.php');
    await page.evaluate(() => { const r = document.querySelector('.reply'); if (r) { r.closest('.msg').scrollIntoView({ block: 'start' }); window.scrollBy(0, -80); } });
    await page.waitForTimeout(300);
    await page.screenshot({ path: `${OUT}/history-${scheme}.png` });
    await ctx.close();
  }
  // Kind-Ansicht (Privatsphäre)
  const kctx = await browser.newContext({ viewport: { width: 390, height: 844 }, deviceScaleFactor: 3 });
  const kpage = await kctx.newPage();
  await login(kpage, 'Mia', '1234');
  await kpage.goto(BASE + 'queue.php');
  await kpage.waitForTimeout(300);
  await kpage.screenshot({ path: `${OUT}/kid-queue-light.png` });
  // Desktop
  const d = await browser.newContext({ viewport: { width: 1440, height: 900 }, deviceScaleFactor: 2 });
  const dp = await d.newPage();
  await login(dp, 'Alex', 'demo-password');
  await dp.goto(BASE + 'hilfe.php?doc=integrationen#4-templates-placeholders-and-filters');
  await dp.waitForTimeout(400);
  await dp.screenshot({ path: `${OUT}/desktop-docs.png` });
  await browser.close();
})();
