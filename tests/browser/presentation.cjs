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
function check(ok, label) { if (!ok) { console.error('FAIL: '+label); throw new Error(label); } checks++; console.log(`PASS: ${label}`); }
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
            await page.setViewportSize({width,height:900});
            for (const area of ['users','merchants','stores','logs','privileges','settings']) {
                stage = `list ${area} width ${width}`;
                check((await page.goto(`${origin}/${area}/index`)).status()===200,`List ${area} ${width}`);
                const heading = page.locator('main table thead tr').first().locator('th').last();
                check((await heading.textContent()).trim()==='Azioni' && await heading.evaluate(el=>getComputedStyle(el).textAlign)==='right',`Actions right ${area} ${width}`);
                check(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),`No page overflow ${area} ${width}`);
                const table = page.locator('main .grid-view > .table-responsive');
                check(await table.count()===1,`Table scroll wrapper ${area} ${width}`);
            }
            stage = `own profile width ${width}`;
            const own = await page.locator('a[data-napay-profile-link]').getAttribute('href');
            check((await page.goto(new URL(own,origin).href)).status()===200,`Own profile ${width}`);
            const modalClose = page.locator('.modal.show [data-bs-dismiss="modal"]');
            if (await modalClose.count()) await modalClose.first().click();
            check(await page.locator('[data-napay-user-profile]').count()===1,`New profile layout ${width}`);
            await page.getByRole('tab',{name:'Preferenze',exact:true}).click();
            check(await page.locator('#tab-settings').evaluate(el=>el.classList.contains('active')),`Preferences tab opens ${width}`);
            check(await page.locator('#pushmenu_collapsed').isVisible(),`Own preferences visible ${width}`);
            await page.getByRole('tab',{name:'Panoramica',exact:true}).click();
            check(await page.locator('[data-napay-account-details]').isVisible(),`Overview tab opens ${width}`);
            check(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),`Profile no page overflow ${width}`);
        }
        console.log(`PASS ${checks} presentation desktop/mobile browser checks`);
    } finally { await browser.close(); }
})().catch(error=>{console.error(`FAIL presentation browser at ${stage} (${error.name})`);process.exitCode=1;});
