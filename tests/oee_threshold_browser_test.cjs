// Real portal markup/assets with an isolated API: never alters live settings.
const { chromium } = require(process.env.PLAYWRIGHT_MODULE || 'playwright');
const fs = require('fs'), path = require('path'), assert = require('assert');
const { execFileSync } = require('child_process');
const root = path.resolve(__dirname, '..');
const grants = JSON.parse(execFileSync('php', ['-r', 'require "includes/permissions.php"; echo json_encode([portal_permission_matrix(true),portal_standard_permissions()]);'], { cwd: root, encoding: 'utf8' }));
const html = execFileSync('php', ['-r', 'require "includes/security.php"; start_app_session(); $_SESSION["user_id"]=1; require "app-shell.php";'], { cwd: root, encoding: 'utf8' });
(async () => {
  const browser = await chromium.launch({ headless: true, ...(process.env.CHROMIUM_EXECUTABLE ? { executablePath: process.env.CHROMIUM_EXECUTABLE } : {}) });
  try {
    const page = await browser.newPage({ viewport: { width: 1440, height: 1000 } });
    const errors = [], writes = [], limits = { 'APP-06': 20, 'APP-07': 25, 'APP-10': 30 };
    let failSave = false, admin = true;
    page.on('pageerror', e => errors.push(e.message));
    await page.route('http://127.0.0.1:4173/**', async route => {
      const url = new URL(route.request().url()), action = url.searchParams.get('action');
      if (url.pathname.endsWith('/app.php')) return route.fulfill({ contentType: 'text/html', body: html });
      if (url.pathname.endsWith('/api.php')) {
        if (url.searchParams.get('resource') === 'bootstrap') return route.fulfill({ json: { csrf_token: 'isolated-test', access: { permissions: grants[admin ? 0 : 1] }, user: { full_name: 'Test Admin', username: 'admin' }, records: [], is_admin: admin } });
        if (url.searchParams.get('resource') === 'users') return route.fulfill({ json: { users: [] } });
        if (action === 'master-save') {
          const data = route.request().postDataJSON(); writes.push(data);
          assert.equal(route.request().headers()['x-csrf-token'], 'isolated-test');
          if (failSave) return route.fulfill({ status: 422, json: { error: 'Test save failure; try again.' } });
          limits[data.line_code] = Number(data.gap_threshold_minutes);
          return route.fulfill({ json: { ok: true } });
        }
        if (action === 'masters') return route.fulfill({ json: { reasons: [], cycles: [], thresholds: Object.entries(limits).map(([line_code, gap_threshold_minutes]) => ({ line_code, gap_threshold_minutes })) } });
        return route.fulfill({ json: { available: false } });
      }
      const file = path.resolve(root, '.' + decodeURIComponent(url.pathname));
      if (!file.startsWith(root + path.sep) || !fs.existsSync(file)) return route.fulfill({ status: 404, body: '' });
      return route.fulfill({ body: fs.readFileSync(file), contentType: file.endsWith('.css') ? 'text/css' : 'application/javascript' });
    });
    await page.goto('http://127.0.0.1:4173/app.php#/oee-masters');
    await page.locator('#thresholds-body tr').first().waitFor({ timeout: 3000 });
    assert.equal(await page.locator('#thresholds-body tr').count(), 3);
    await page.locator('#threshold-line').selectOption('APP-07');
    assert.equal(await page.locator('#threshold-minutes').inputValue(), '25');
    await page.locator('#threshold-minutes').fill('27.5');
    await page.locator('#threshold-save').click();
    await page.waitForFunction(() => document.getElementById('masters-alert').textContent.includes('APP-07'));
    assert.deepEqual(writes[0], { master_type: 'threshold', line_code: 'APP-07', gap_threshold_minutes: '27.5' });
    assert.equal(limits['APP-06'], 20);
    await page.reload();
    await page.locator('#thresholds-body tr').first().waitFor();
    await page.getByRole('button', { name: 'Edit threshold for APP-07' }).click();
    assert.equal(await page.locator('#threshold-line').inputValue(), 'APP-07');
    assert.equal(await page.locator('#threshold-minutes').inputValue(), '27.5');
    await page.locator('#threshold-minutes').fill('0');
    const count = writes.length;
    await page.locator('#threshold-save').click();
    assert.equal(writes.length, count, 'Browser must reject zero before sending');
    failSave = true;
    await page.locator('#threshold-minutes').fill('28');
    await page.locator('#threshold-save').click();
    await page.waitForFunction(() => document.getElementById('masters-alert').textContent.includes('Test save failure'));
    assert.equal(await page.locator('#threshold-minutes').inputValue(), '28');
    assert(!(await page.locator('#threshold-save').isDisabled()));
    for (const width of [1440, 1024, 768, 320]) {
      await page.setViewportSize({ width, height: 1000 });
      await page.waitForTimeout(250); // Allow the existing sidebar/layout transition to settle.
      for (const id of ['threshold-line', 'threshold-minutes', 'threshold-save']) {
        const box = await page.locator('#' + id).boundingBox();
        assert(box && box.x >= 0 && box.x + box.width <= width + 1, id + ' overflows at ' + width);
      }
    }
    await page.setViewportSize({ width: 1440, height: 1000 });
    await page.waitForTimeout(250);
    await page.screenshot({ path: '/tmp/pns-threshold-masters.png', fullPage: true });
    admin = false;
    await page.reload(); await page.locator('#thresholds-body tr').first().waitFor();
    assert(await page.locator('#threshold-save').isDisabled(), 'Non-admin must not get an editable form');
    assert.deepEqual(errors, []);
    console.log('Threshold browser tests passed: plants, edit/save/reload, validation, CSRF, failure recovery, read-only and responsive layout.');
  } finally { await browser.close(); }
})().catch(e => { console.error(e); process.exit(1); });
