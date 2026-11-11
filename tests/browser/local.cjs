// Opt-in browser smoke test against docker/local.sh only. Never use operational credentials.
const fs = require('node:fs');
const path = require('node:path');
const os = require('node:os');
const crypto = require('node:crypto');
const { execFileSync } = require('node:child_process');
const { chromium } = require(process.env.PLAYWRIGHT_PACKAGE || 'playwright');
const root = path.resolve(__dirname, '../..');
const state = path.join(os.homedir(), '.local/share/napay/browser-local');
const origin = 'https://napay.local';
const ca = path.join(state, 'caddy-root.crt');
const compose = ['compose', '-p', 'napay-browser-local', '--project-directory', root,
  '--env-file', path.join(state, 'local.env'), '-f', path.join(root, 'docker-compose.yml'),
  '-f', path.join(root, 'docker/local/compose.yml')];
let checks = 0;
let stage = 'startup';
function check(value, label) {
  if (!value) throw new Error(label);
  console.log(`PASS: ${label}`);
  ++checks;
}
function mailLink(email, route) {
  execFileSync('bash', [path.join(root, 'docker/local.sh'), 'mail'], { stdio: 'pipe' });
  const directory = path.join(state, 'mail-review');
  const messages = fs.readdirSync(directory).filter(name => name.endsWith('.eml'))
    .map(name => ({ name, time: fs.statSync(path.join(directory, name)).mtimeMs }))
    .sort((a, b) => b.time - a.time);
  for (const { name } of messages) {
    const raw = fs.readFileSync(path.join(directory, name), 'utf8');
    if (!raw.split(/\r?\n/).some(line => line === `To: ${email}`)) continue;
    const decoded = raw.replace(/=\r?\n/g, '').replace(/=([0-9A-F]{2})/gi, (_, hex) => String.fromCharCode(parseInt(hex, 16)));
    const match = decoded.match(new RegExp(`https://napay\\.local/site/${route}\\?[^"\\s<>]+`));
    if (match) return match[0].replace(/&amp;/g, '&');
  }
  throw new Error('Expected private file mail missing');
}
async function submit(page, selector) {
  await Promise.all([page.waitForNavigation({ waitUntil: 'domcontentloaded' }), page.locator(selector).click()]);
}
(async () => {
  // curl validates the complete TLS chain against the exported local CA (no -k).
  execFileSync('curl', ['--fail', '--silent', '--cacert', ca, '--output', '/dev/null', `${origin}/site/login`]);
  check(true, 'TLS chain and napay.local certificate verified');
  const redirect = execFileSync('curl', ['--silent', '--head', 'http://napay.local/site/login']).toString();
  check(/HTTP\/\S+ (301|308)/.test(redirect) && redirect.includes(`${origin}/site/login`), 'HTTP redirects to HTTPS');
  // Pin only this sandbox leaf key in the isolated automated browser; no global TLS bypass.
  const certificate = execFileSync('openssl', ['s_client', '-connect', '127.0.0.1:443', '-servername', 'napay.local', '-showcerts'], { input: '', stdio: ['pipe', 'pipe', 'ignore'] });
  const publicKey = execFileSync('openssl', ['x509', '-pubkey', '-noout'], { input: certificate });
  const der = execFileSync('openssl', ['pkey', '-pubin', '-outform', 'der'], { input: publicKey });
  const pin = crypto.createHash('sha256').update(der).digest('base64');
  const browser = await chromium.launch({ executablePath: process.env.CHROME_PATH || '/usr/bin/google-chrome',
    headless: true, args: [`--ignore-certificate-errors-spki-list=${pin}`] });
  try {
    let context = await browser.newContext();
    await context.route('**/*', route => new URL(route.request().url()).origin === origin ? route.continue() : route.abort());
    let page = await context.newPage();
    page.setDefaultTimeout(15000);
    stage = 'login';
    check((await page.goto(`${origin}/site/login`)).status() === 200, 'Desktop login loads through Caddy');
    await page.locator('[name="LoginForm[email]"]').fill('browser-admin@example.invalid');
    await page.locator('[name="LoginForm[password]"]').fill(fs.readFileSync(path.join(state, 'browser-password'), 'utf8').trim());
    await submit(page, '#login-form button[type=submit]');
    check(!new URL(page.url()).pathname.includes('/login'), 'Local administrator login succeeds');
    check((await page.goto(`${origin}/users/index`)).status() === 200, 'Authorized users page opens');
    const cookies = await context.cookies();
    for (const name of ['PHPSESSID', '_csrf']) {
      const cookie = cookies.find(item => item.name === name);
      check(cookie && cookie.secure && cookie.httpOnly && cookie.sameSite === 'Lax', `${name} cookie protected over HTTPS`);
    }
    stage = 'account dropdown';
    check(await page.locator('aside [data-napay-profile-link], aside a[href*="/users/view"]').count() === 0, 'Sidebar no longer contains profile');
    check(await page.locator('aside a[href*="/debug"], aside a[href*="/gii"]').count() === 0, 'Developer links absent');
    for (const width of [1280, 390]) {
      await page.setViewportSize({ width, height: 900 });
      await page.locator('#napay-account-menu').click();
      check(await page.locator('.napay-topbar [data-napay-profile-link]').isVisible(), `Account dropdown profile visible at ${width}px`);
      const [profile] = await Promise.all([
        page.waitForNavigation({ waitUntil: 'domcontentloaded' }),
        page.locator('.napay-topbar [data-napay-profile-link]').click(),
      ]);
      check(profile.status() === 200 && new URL(page.url()).pathname === '/users/view', `Own profile opens from top-right menu at ${width}px`);
    }
    await page.locator('#napay-account-menu').click();
    check(await page.locator('.napay-topbar a[href="/site/logout"]').getAttribute('data-method') === 'post', 'Dropdown logout keeps POST contract');
    await submit(page, '.napay-topbar a[href="/site/logout"]');
    check(new URL(page.url()).pathname === '/site/login', 'Dropdown logout returns to login');
    // A fresh anonymous context avoids in-flight admin responses restoring cookies.
    await context.close();
    context = await browser.newContext();
    await context.route('**/*', route => new URL(route.request().url()).origin === origin ? route.continue() : route.abort());
    page = await context.newPage();
    page.setDefaultTimeout(15000);
    stage = 'mobile login';
    await page.setViewportSize({ width: 390, height: 844 });
    await page.goto(`${origin}/site/login`);
    check(await page.locator('#login-form button[type=submit]').isVisible(), 'Mobile login submit visible');
    check(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth), 'Mobile login has no horizontal overflow');
    stage = 'signup';
    const suffix = crypto.randomBytes(6).toString('hex');
    const username = `browser-${suffix}`;
    const email = `${username}@example.invalid`;
    const password = crypto.randomBytes(24).toString('hex');
    await page.goto(`${origin}/site/signup`);
    check(new URL(page.url()).pathname === '/site/signup', 'Anonymous browser reaches registration form');
    await page.locator('#is_merchant').selectOption('0');
    for (const [field, value] of Object.entries({ email, first_name: 'Browser', last_name: 'Smoke', password, repeat_password: password })) {
      await page.locator(`[name="SignupForm[${field}]"]`).fill(value);
    }
    for (const field of ['consenso_statuto', 'consenso_privacy', 'consenso_condizioni']) {
      await page.locator(`input[type=checkbox][name="UserConsensus[${field}]"]`).check();
    }
    await submit(page, '#signup-form button[type=submit]');
    check(new URL(page.url()).pathname.includes('/login'), 'Browser registration succeeds');
    stage = 'activation';
    const activation = mailLink(email, 'activate');
    check((await page.goto(activation)).status() === 200, 'Activation link from file mail opens via HTTPS');
    await submit(page, 'form button[type=submit]');
    check(new URL(page.url()).pathname.includes('/login'), 'Activation confirmation succeeds');
    check((await page.goto(activation)).status() === 400, 'Consumed activation link rejected');
    stage = 'reset';
    await page.goto(`${origin}/site/forgot-password`);
    await page.locator('[name="PasswordResetRequestForm[email]"]').fill(email);
    await submit(page, 'form button[type=submit]');
    const reset = mailLink(email, 'reset-password');
    check((await page.goto(reset)).status() === 200, 'Password link from file mail opens via HTTPS');
    const nextPassword = crypto.randomBytes(24).toString('hex');
    await page.locator('[name="ResetPasswordForm[password]"]').fill(nextPassword);
    await page.locator('[name="ResetPasswordForm[repeat_password]"]').fill(nextPassword);
    await submit(page, 'form button[type=submit]');
    check(new URL(page.url()).pathname.includes('/login'), 'Password replacement succeeds');
    check((await page.goto(reset)).status() === 400, 'Consumed password link rejected');
    await page.goto(`${origin}/site/login`);
    await page.locator('[name="LoginForm[email]"]').fill(email);
    await page.locator('[name="LoginForm[password]"]').fill(nextPassword);
    await submit(page, '#login-form button[type=submit]');
    check(!new URL(page.url()).pathname.includes('/login'), 'New password authenticates in browser');
    check((await page.goto(`${origin}/users/index`)).status() === 403, 'Ordinary member cannot access administrator users page');
    await context.close();
    fs.rmSync(path.join(state, 'browser-failure.log'), { force: true });
    console.log(`PASS ${checks} local HTTPS browser checks`);
  } finally {
    await browser.close();
  }
})().catch(error => {
  // Never include browser errors/URLs: activation and reset query strings are credentials.
  console.error(`FAIL: local browser stage ${stage}; no bearer URLs logged`);
  fs.writeFileSync(path.join(state, 'browser-failure.log'), String(error), { mode: 0o600 });
  process.exitCode = 1;
});
