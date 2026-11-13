// Read-only navigation smoke in the dedicated local browser sandbox. No business-data writes.
'use strict';
const fs = require('node:fs');
const path = require('node:path');
const crypto = require('node:crypto');
const {execFileSync} = require('node:child_process');
const {chromium} = require(process.env.PLAYWRIGHT_PACKAGE || 'playwright');
const state = process.env.NAPAY_LOCAL_STATE;
if (!state || !path.isAbsolute(state)) throw new Error('Set NAPAY_LOCAL_STATE.');
const origin = 'https://napay.local';
let checks = 0;
let stage = 'startup';
function check(ok, label) { if (!ok) throw new Error(label); checks++; console.log(`PASS: ${label}`); }
(async () => {
    execFileSync('curl', ['--fail','--silent','--cacert',path.join(state,'caddy-root.crt'),'--output','/dev/null',`${origin}/site/login`]);
    const certificate = execFileSync('openssl',['s_client','-connect','127.0.0.1:443','-servername','napay.local','-showcerts'],{input:'',stdio:['pipe','pipe','ignore']});
    const publicKey = execFileSync('openssl',['x509','-pubkey','-noout'],{input:certificate});
    const der = execFileSync('openssl',['pkey','-pubin','-outform','der'],{input:publicKey});
    const pin = crypto.createHash('sha256').update(der).digest('base64');
    const browser = await chromium.launch({executablePath:process.env.CHROME_PATH || '/usr/bin/google-chrome', headless:true,
        args:[`--ignore-certificate-errors-spki-list=${pin}`]});
    try {
        const context = await browser.newContext();
        await context.route('**/*',route=>new URL(route.request().url()).origin===origin ? route.continue() : route.abort());
        const page = await context.newPage();
        page.setDefaultTimeout(15000);
        stage = 'local test account login';
        await page.goto(`${origin}/site/login`);
        await page.locator('[name="LoginForm[email]"]').fill('browser-admin@example.invalid');
        await page.locator('[name="LoginForm[password]"]').fill(fs.readFileSync(path.join(state,'browser-password'),'utf8').trim());
        await Promise.all([page.waitForNavigation({waitUntil:'domcontentloaded'}),page.locator('#login-form button[type=submit]').click()]);
        check(!page.url().includes('/site/login'),'Local sandbox login');
        for (const width of [1280,390]) {
            stage = `navigation width ${width}`;
            await page.setViewportSize({width,height:900});
            check((await page.goto(`${origin}/users/index`)).status()===200,`Users page ${width}`);
            const modalClose = page.locator('.modal.show [data-bs-dismiss="modal"]');
            if (await modalClose.count()) await modalClose.first().click();
            if (width < 992) {
                await page.locator('aside .navbar-toggler').click();
                await page.waitForFunction(()=>document.getElementById('napay-sidebar-menu').classList.contains('show'));
            }
            const group = page.locator('aside button.dropdown-toggle').filter({hasText:'Associazione'});
            await group.click();
            check(await group.getAttribute('aria-expanded')==='true',`Group opens ${width}`);
            await group.press('ArrowDown');
            await page.waitForFunction(()=>document.activeElement.matches('.dropdown-menu .dropdown-item'));
            check(true,`Keyboard enters submenu ${width}`);
            await page.keyboard.press('Escape');
            check(await group.getAttribute('aria-expanded')==='false',`Escape closes submenu ${width}`);
            await group.click();
            const menu = page.locator('#'+await group.getAttribute('aria-controls'));
            await Promise.all([page.waitForNavigation({waitUntil:'domcontentloaded'}),menu.getByRole('link',{name:'Soci',exact:true}).click()]);
            check(new URL(page.url()).pathname==='/association/members',`Submenu link works ${width}`);
            if (width < 992) {
                await page.locator('aside .navbar-toggler').click();
                await page.waitForFunction(()=>document.getElementById('napay-sidebar-menu').classList.contains('show'));
            }
            check(await page.locator('aside button.dropdown-toggle').filter({hasText:'Associazione'}).getAttribute('aria-expanded')==='true',`Active group expanded ${width}`);
        }
        console.log(`PASS ${checks} desktop/mobile navigation browser checks`);
    } finally { await browser.close(); }
})().catch(error=>{console.error(`FAIL navigation browser at ${stage} (${error.name})`);process.exitCode=1;});
