// Run against HTML exported by layout.php; no application server or payment API.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const http = require('node:http');
const { chromium } = require(process.env.NAPAY_PLAYWRIGHT_MODULE || 'playwright');
const root = path.resolve(__dirname, '../..');
const output = process.env.NAPAY_UI_OUTPUT || '/tmp/napay-ui-pilot';
const mime = { '.html': 'text/html', '.css': 'text/css', '.js': 'application/javascript', '.png': 'image/png', '.svg': 'image/svg+xml', '.woff2': 'font/woff2', '.woff': 'font/woff' };
let assertions = 0;
function check(condition, message) { assert.ok(condition, message); assertions++; }
const server = http.createServer((req, res) => {
    let pathname;
    try { pathname = decodeURIComponent(new URL(req.url, 'http://fixture').pathname); }
    catch { res.writeHead(400).end(); return; }
    const base = pathname.startsWith('/bundles/') ? path.join(root, 'web') : output;
    const file = path.resolve(base, '.' + pathname);
    if (!file.startsWith(base + path.sep)) { res.writeHead(403).end(); return; }
    if (!fs.existsSync(file) || !fs.statSync(file).isFile()) { res.writeHead(404).end(); return; }
    res.setHeader('Content-Type', mime[path.extname(file)] || 'application/octet-stream');
    fs.createReadStream(file).pipe(res);
});
(async () => {
    let browser;
    try {
        await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
        const base = `http://127.0.0.1:${server.address().port}`;
        browser = await chromium.launch({ headless: true, args: ['--no-sandbox'] });
        fs.mkdirSync(path.join(output, 'screenshots'), { recursive: true });
        for (const width of [375, 768, 1440]) {
            const context = await browser.newContext({ viewport: { width, height: 960 }, serviceWorkers: 'block' });
            const page = await context.newPage();
            page.setDefaultTimeout(10000);
            const errors = [];
            page.on('pageerror', error => errors.push(error.message));
            await page.goto(base + '/admin.html', { waitUntil: 'networkidle' });
            check(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), `No page overflow at ${width}px`);
            check(await page.evaluate(() => !window.pwned), 'Profile data remains text');
            if (width < 992) {
                await page.screenshot({ path: path.join(output, 'screenshots', `before-${width}.png`), fullPage: true });
                await page.locator('.navbar-toggler').click();
                await page.locator('#napay-sidebar-menu.show').waitFor();
                check(await page.locator('#napay-sidebar-menu').isVisible(), `Mobile navigation opens at ${width}px`);
                await page.screenshot({ path: path.join(output, 'screenshots', `menu-${width}.png`), fullPage: true });
            } else {
                await page.screenshot({ path: path.join(output, 'screenshots', 'dashboard-light.png'), fullPage: true });
                const before = await page.locator('aside.navbar-vertical').evaluate(el => el.getBoundingClientRect().width);
                await page.locator('[data-napay-sidebar-toggle]').click();
                const after = await page.locator('aside.navbar-vertical').evaluate(el => el.getBoundingClientRect().width);
                check(after < before && after < 100, 'Desktop sidebar folds');
                await page.locator('[data-napay-theme-toggle]').click();
                check(await page.locator('html').getAttribute('data-bs-theme') === 'dark', 'Dark theme applied');
                check(await page.locator('.navbar-brand-image').evaluate(el => getComputedStyle(el).filter === 'none'), 'Brand logo is not inverted in dark mode');
                await page.screenshot({ path: path.join(output, 'screenshots', 'dashboard-dark-folded.png'), fullPage: true });
            }
            check(errors.length === 0, `No uncaught JS errors at ${width}px: ${errors.join(', ')}`);
            await context.close();
        }
        const context = await browser.newContext({ viewport: { width: 1440, height: 960 }, serviceWorkers: 'block' });
        const page = await context.newPage();
        page.setDefaultTimeout(10000);
        const errors = [];
        page.on('pageerror', error => errors.push(error.message));
        await page.goto(base + '/form.html', { waitUntil: 'networkidle' });
        await page.locator('#pilot-description').fill('Bozza modificata, non salvata');
        await page.locator('[data-napay-theme-toggle]').click();
        check(await page.locator('#pilot-description').inputValue() === 'Bozza modificata, non salvata', 'Theme toggle preserves dirty form');
        await page.reload({ waitUntil: 'networkidle' });
        check(await page.locator('html').getAttribute('data-bs-theme') === 'dark', 'Theme preference survives reload');
        await page.locator('.select2-selection').click();
        await page.getByRole('option', { name: 'POA', exact: true }).click();
        check(await page.locator('#pilot-network').inputValue() === 'POA', 'Select2 remains operational');
        check(await page.locator('.select2-selection').evaluate(el => getComputedStyle(el).backgroundColor !== 'rgb(255, 255, 255)'), 'Select2 follows dark theme');
        await page.locator('#pilot-date').click();
        await page.locator('.datepicker-dropdown').waitFor({ state: 'visible' });
        check(await page.locator('.datepicker-dropdown').isVisible(), 'DatePicker opens');
        await page.locator('#pilot-date').press('Escape');
        await page.locator('#pilot-open-modal').click();
        await page.locator('#pilot-modal.show').waitFor();
        check(await page.locator('.modal-backdrop').count() === 1, 'One modal backdrop');
        await page.locator('#pilot-modal [data-bs-dismiss="modal"]').first().click();
        await page.locator('.modal-backdrop').waitFor({ state: 'detached' });
        check(!await page.locator('body').evaluate(el => el.classList.contains('modal-open')), 'Modal restores page scrolling');
        await page.locator('#pushmenu_collapsed').uncheck();
        check(await page.locator('html').getAttribute('data-napay-sidebar') === 'folded', 'Profile checkbox controls same sidebar state');
        await page.screenshot({ path: path.join(output, 'screenshots', 'widgets-dark.png'), fullPage: true });
        check(errors.length === 0, 'No widget JavaScript errors: ' + errors.join(', '));
        await context.close();
        const invalid = await browser.newContext({ serviceWorkers: 'block' });
        await invalid.addInitScript(() => {
            localStorage.setItem('napay.theme', '"><svg onload=alert(1)>');
            localStorage.setItem('napay.sidebar', 'not-an-allowed-value');
        });
        const invalidPage = await invalid.newPage();
        await invalidPage.goto(base + '/admin.html', { waitUntil: 'networkidle' });
        check(await invalidPage.locator('html').getAttribute('data-bs-theme') === 'light', 'Invalid stored theme is ignored');
        check(await invalidPage.locator('html').getAttribute('data-napay-sidebar') === 'expanded', 'Invalid stored sidebar state is ignored');
        await invalid.close();
        // Disabled browser storage must not prevent switching the current page.
        const blocked = await browser.newContext({ serviceWorkers: 'block' });
        await blocked.addInitScript(() => { Object.defineProperty(window, 'localStorage', { get() { throw new Error('Storage blocked'); } }); });
        const offlineStorage = await blocked.newPage();
        await offlineStorage.goto(base + '/admin.html', { waitUntil: 'networkidle' });
        await offlineStorage.locator('[data-napay-theme-toggle]').click();
        check(await offlineStorage.locator('html').getAttribute('data-bs-theme') === 'dark', 'Theme switch works when storage is blocked');
        await blocked.close();
        console.log(`PASS ${assertions} browser checks; screenshots: ${path.join(output, 'screenshots')}`);
    } finally {
        if (browser) await browser.close();
        server.close();
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
