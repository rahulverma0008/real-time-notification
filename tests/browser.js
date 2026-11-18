/**
 * Browser test: a real notification appears on the store front (headless Chrome).
 *   node tests/browser.js <site-url> [chrome]      (puppeteer-core in $STRIPEPRO_TEST_DIR/node/node_modules)
 * The e2e test seeds an order of "Browser Bob" and the settings first.
 */
const path = require('path');
const work = process.env.STRIPEPRO_TEST_DIR || '/tmp/stripepro-test';
const puppeteer = require(path.join(work, 'node', 'node_modules', 'puppeteer-core'));
const SITE = process.argv[2].replace(/\/$/, '');
const CHROME = process.argv[3] || '/usr/bin/google-chrome';
let failed = 0, passed = 0;
const check = (name, ok, detail) => { if (ok) { passed++; console.log('  \x1b[32mok\x1b[0m    ' + name); } else { failed++; console.log('  \x1b[31mFAIL\x1b[0m  ' + name + (detail ? '\n        ' + detail : '')); } };

(async () => {
    const browser = await puppeteer.launch({executablePath: CHROME, headless: 'new', args: ['--no-sandbox', '--disable-gpu']});
    const page = await browser.newPage();
    await page.setViewport({width: 1200, height: 800});
    const problems = [];
    page.on('pageerror', (e) => problems.push(e.message));
    page.on('console', (m) => { if (m.type() == 'error' && !/Failed to load resource/.test(m.text())) problems.push(m.text()); });
    await page.setRequestInterception(true);
    page.on('request', (r) => (r.url().startsWith(SITE) || r.url().startsWith('data:')) ? r.continue() : r.abort());

    await page.goto(SITE + '/index.php?route=common/home&language=en-gb', {waitUntil: 'networkidle0'});

    // the first poll is after 5 seconds, the notification is shown a bit later
    try {
        await page.waitForSelector('.alert-notification', {visible: true, timeout: 20000});
    } catch (e) {
        check('a notification appears on the store front', false, 'none after 20 seconds');
        await browser.close(); console.log(passed + ' passed, ' + failed + ' failed'); process.exit(1);
    }
    check('a notification appears on the store front', true);

    const info = await page.evaluate(() => {
        const el = document.querySelector('.alert-notification');
        const r = el.getBoundingClientRect();
        const img = el.querySelector('img');
        return {
            text: el.textContent.trim(),
            link: el.querySelector('a') ? el.querySelector('a').getAttribute('href') : '',
            imgLoaded: !!img && img.complete && img.naturalWidth > 0,
            left: r.left, top: r.top, width: r.width, height: r.height, vw: window.innerWidth, vh: window.innerHeight,
            dismiss: !!el.querySelector('[data-notify="dismiss"]'),
            progress: !!el.querySelector('.progress'),
            html: el.innerHTML
        };
    });

    check('it tells who bought what', /Browser Bob from Bristol just bought/.test(info.text), info.text);
    check('product link is set', /product_id=/.test(info.link), info.link);
    check('product image is loaded', info.imgLoaded);
    check('placed at the bottom left (position setting)', info.left < info.vw / 2 && info.top > info.vh / 2, JSON.stringify([info.left, info.top, info.vw, info.vh]));
    check('is a visible card, not a collapsed element', info.width > 100 && info.height > 30, info.width + 'x' + info.height);
    check('dismiss button is shown (setting on)', info.dismiss);
    check('progress bar is shown (setting on)', info.progress);
    check('no markup of the name is injected', !/<script/i.test(info.html));

    await page.click('.alert-notification [data-notify="dismiss"]');
    await page.waitForFunction(() => !document.querySelector('.alert-notification'), {timeout: 5000}).then(() => check('dismiss button closes it', true), () => check('dismiss button closes it', false));
    check('no JavaScript errors on the page', problems.length == 0, problems.join('\n        '));

    await browser.close();
    console.log('\n' + passed + ' passed, ' + failed + ' failed');
    process.exit(failed ? 1 : 0);
})().catch((e) => { console.error(e); process.exit(1); });
