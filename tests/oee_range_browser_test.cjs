// Isolated UI test: real project PHP/JS/CSS, deterministic API responses.
// PLAYWRIGHT_MODULE and CHROMIUM_EXECUTABLE may point to installed runtimes.
const { chromium } = require(process.env.PLAYWRIGHT_MODULE || 'playwright');
const fs = require('fs');
const path = require('path');
const assert = require('assert');
const { execFileSync } = require('child_process');
const root = path.resolve(__dirname, '..');
const fullPermissions = JSON.parse(execFileSync('php', ['-r', 'require "includes/permissions.php"; echo json_encode(portal_permission_matrix(true));'], { cwd: root, encoding: 'utf8' }));
const html = execFileSync('php', ['-r', 'require "includes/security.php"; start_app_session(); $_SESSION["user_id"]=1; require "app-shell.php";'], { cwd: root, encoding: 'utf8' });
(async () => {
  const browser = await chromium.launch({ headless: true, ...(process.env.CHROMIUM_EXECUTABLE ? { executablePath: process.env.CHROMIUM_EXECUTABLE } : {}) });
  try {
    const page = await browser.newPage({ viewport: { width: 1440, height: 1000 }, acceptDownloads: true });
    const errors = [], requests = [];
    page.on('pageerror', e => errors.push(e.message));
    await page.route('http://127.0.0.1:4173/**', async route => {
      const url = new URL(route.request().url()), q = url.searchParams;
      if (url.pathname.endsWith('/app.php')) return route.fulfill({ contentType: 'text/html', body: html });
      if (url.pathname.endsWith('/api.php')) {
        requests.push(Object.fromEntries(q));
        if (q.get('resource') === 'bootstrap') return route.fulfill({ json: { csrf_token: 'test', access: { permissions: fullPermissions }, user: { full_name: 'Test Operator', username: 'operator' }, records: [], is_admin: false, user_count: 1 } });
        if (q.get('resource') === 'users') return route.fulfill({ json: { users: [], assignable_roles: [] } });
        if (q.get('action') === 'range-export') return route.fulfill({ contentType: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', body: Buffer.from([80, 75, 3, 4]) });
        const days = (Date.parse(q.get('to_date')) - Date.parse(q.get('from_date'))) / 86400000 + 1 || 1;
        const minutes = Number(q.get('duration_minutes') || 1440);
        return route.fulfill({ json: { available: true, date: '2026-10-22', from_date: q.get('from_date'), to_date: q.get('to_date'), machine: q.get('machine') || 'ALL',
          window: { duration_minutes: minutes, duration_hours: minutes / 60, start_time: q.get('start_time') || '00:00', day_count: days, window_start: '2026-10-22 00:00:00', window_end: '2026-10-23 00:00:00' },
          metrics: { total_fb: days * 10, productive_fb: days * 10, pcs: days * 100 },
          oee: { oee: 0.5, availability: 0.8, performance: 0.625, quality: 1, utilization_window: 0.8, calendar_minutes: days * minutes, calculation_flags: {} },
          rows: [], line_metrics: [{ line_code: 'APP-06', productive_fb: days * 10, run_minutes: days * 600, ideal_output_minutes: days * 300, availability: 0.8, performance: 0.625, quality: 1, oee: 0.5, utilization_window: 0.8 }], window_metrics: [], sync: [], imports: [] } });
      }
      const file = path.resolve(root, '.' + decodeURIComponent(url.pathname));
      if (!file.startsWith(root + path.sep) || !fs.existsSync(file)) return route.fulfill({ status: 404, body: '' });
      return route.fulfill({ body: fs.readFileSync(file), contentType: file.endsWith('.css') ? 'text/css' : 'application/javascript' });
    });
    await page.goto('http://127.0.0.1:4173/app.php#/dashboard');
    await page.waitForFunction(() => document.getElementById('dashboard-date').value === '2026-10-22');
    await page.locator('[data-nav="oee-range"]').click();
    await page.waitForFunction(() => document.getElementById('range-oee').textContent === '50.0%');
    assert.equal(await page.locator('#page-title').textContent(), 'Date-range OEE');
    assert.equal(await page.locator('#dashboard-date').inputValue(), '2026-10-22');
    await page.locator('#range-from-date').fill('2026-10-21');
    await page.locator('#range-to-date').fill('2026-10-22');
    await page.locator('#range-duration').selectOption('720');
    assert(!(await page.locator('#range-start-time').isDisabled()));
    await page.locator('#range-start-time').fill('18:00');
    await page.locator('#range-load').click();
    await page.waitForFunction(() => document.getElementById('range-days').textContent === '2');
    assert((await page.locator('#range-window').textContent()).includes('2 day(s) × 720 min'));
    assert.equal(await page.locator('#range-total-fb').textContent(), '20');
    assert.equal(await page.locator('#range-calendar').textContent(), '1440.0 min');
    assert.equal(await page.locator('#dashboard-duration').inputValue(), '1440', 'daily controls must stay independent');
    const downloadPromise = page.waitForEvent('download');
    await page.locator('#range-export').click();
    const download = await downloadPromise;
    assert.equal(download.suggestedFilename(), 'pns-oee-2026-10-21-to-2026-10-22-ALL-720min-1800.xlsx');
    const exported = requests.find(r => r.action === 'range-export');
    assert.equal(exported.from_date, '2026-10-21'); assert.equal(exported.to_date, '2026-10-22'); assert.equal(exported.start_time, '18:00');
    await page.locator('#range-from-date').fill('2026-10-23');
    const before = requests.filter(r => r.action === 'range-dashboard').length;
    await page.locator('#range-load').click();
    await page.waitForFunction(() => !document.getElementById('range-alert').hidden);
    assert.equal(requests.filter(r => r.action === 'range-dashboard').length, before, 'reversed range must not reach API');
    assert((await page.locator('#range-alert').textContent()).includes('From date'));
    await page.locator('#range-from-date').fill('2026-10-21');
    await page.locator('#range-machine').selectOption('APP-07');
    await page.locator('#range-load').click();
    await page.waitForFunction(() => document.getElementById('range-window').textContent.includes('APP-07'));
    for (const width of [1440, 1024, 768, 320]) {
      await page.setViewportSize({ width, height: 1000 }); await page.waitForTimeout(250);
      for (const id of ['range-from-date', 'range-to-date', 'range-machine', 'range-duration', 'range-start-time', 'range-load', 'range-export']) {
        const box = await page.locator('#' + id).boundingBox();
        assert(box && box.x >= 0 && box.x + box.width <= width + 1, id + ' overflows at ' + width);
      }
    }
    await page.setViewportSize({ width: 1440, height: 1000 }); await page.waitForTimeout(250);
    await page.screenshot({ path: '/tmp/pns-range-dashboard.png', fullPage: true });
    await page.locator('[data-nav="dashboard"]').click();
    await page.locator('[data-nav="oee-range"]').click();
    assert.equal(await page.locator('#range-from-date').inputValue(), '2026-10-21');
    assert.equal(await page.locator('#range-machine').inputValue(), 'APP-07');
    assert.deepEqual(errors, []);
    console.log('Date-range browser tests passed: separate route, totals, validation, independent filters, export, 320/768/1024/1440 layouts.');
  } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exit(1); });
