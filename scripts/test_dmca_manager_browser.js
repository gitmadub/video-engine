const fs = require('fs');
const { chromium } = require('playwright');

function firstExistingPath(paths) {
  for (const candidate of paths) {
    if (candidate && fs.existsSync(candidate)) {
      return candidate;
    }
  }

  return null;
}

function requiredEnv(name) {
  const value = process.env[name];

  if (!value) {
    throw new Error(`Missing required environment variable: ${name}`);
  }

  return value;
}

async function loginAndOpenDmcaPage(browser, baseURL, username, password) {
  const context = await browser.newContext({ baseURL });
  const page = await context.newPage();
  await page.goto('/', { waitUntil: 'networkidle' });

  const token = await page.evaluate(() => window.VE_CSRF_TOKEN || '');

  if (!token) {
    throw new Error('Unable to extract runtime CSRF token.');
  }

  const loginResult = await page.evaluate(async (credentials) => {
    const response = await fetch('/api/auth/login', {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        Accept: 'application/json',
        'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
      },
      body: new URLSearchParams({
        login: credentials.username,
        password: credentials.password,
        token: credentials.token,
      }).toString(),
    });

    let payload = {};

    try {
      payload = await response.json();
    } catch (error) {
      payload = {};
    }

    return {
      status: response.status,
      payload,
    };
  }, { username, password, token });

  if (loginResult.status !== 200 || loginResult.payload.status !== 'redirect') {
    throw new Error(`Login failed for ${username}: ${JSON.stringify(loginResult)}`);
  }

  await page.goto('/dashboard/dmca-manager', { waitUntil: 'networkidle' });
  return { context, page };
}

(async () => {
  const baseURL = requiredEnv('DMCA_BROWSER_BASE_URL');
  const username = requiredEnv('DMCA_BROWSER_USER');
  const password = requiredEnv('DMCA_BROWSER_PASSWORD');
  const emptyUsername = requiredEnv('DMCA_BROWSER_EMPTY_USER');
  const emptyPassword = requiredEnv('DMCA_BROWSER_EMPTY_PASSWORD');
  const caseCode = requiredEnv('DMCA_BROWSER_CASE_CODE');
  const browserPath = firstExistingPath([
    process.env.DMCA_BROWSER_EXECUTABLE || '',
    'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe',
    'C:\\Program Files (x86)\\Google\\Chrome\\Application\\chrome.exe',
    'C:\\Program Files\\Microsoft\\Edge\\Application\\msedge.exe',
    'C:\\Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe',
  ]);

  if (!browserPath) {
    throw new Error('Unable to find a Chromium-based browser executable for Playwright.');
  }

  const browser = await chromium.launch({
    headless: true,
    executablePath: browserPath,
  });

  try {
    const emptySession = await loginAndOpenDmcaPage(browser, baseURL, emptyUsername, emptyPassword);
    await emptySession.page.waitForFunction(() => {
      const emptyState = document.querySelector('[data-dmca-empty]');
      return Boolean(emptyState && !emptyState.classList.contains('d-none'));
    });

    const emptyCopy = await emptySession.page.locator('[data-dmca-empty]').textContent();
    const emptyOpen = (await emptySession.page.locator('[data-dmca-open]').textContent() || '').trim();

    if (!String(emptyCopy || '').includes('No active DMCA complaints')) {
      throw new Error(`Empty-state user should see the DMCA empty copy. Received: ${emptyCopy || '(empty)'}`);
    }

    if (emptyOpen !== '0') {
      throw new Error(`Empty-state user should have zero open cases. Received: ${emptyOpen || '(empty)'}`);
    }

    await emptySession.context.close();

    const activeSession = await loginAndOpenDmcaPage(browser, baseURL, username, password);
    const page = activeSession.page;
    await page.waitForFunction((expectedCaseCode) => {
      const list = document.querySelector('[data-dmca-list]');
      return Boolean(list && list.textContent && list.textContent.includes(expectedCaseCode));
    }, caseCode);

    const openCases = (await page.locator('[data-dmca-open]').textContent() || '').trim();

    if (openCases !== '2') {
      throw new Error(`Seeded active user should show two open DMCA cases. Received: ${openCases || '(empty)'}`);
    }

    await page.locator(`[data-dmca-view="${caseCode}"]`).first().click();
    await page.waitForFunction((expectedCaseCode) => {
      const title = document.querySelector('[data-dmca-modal-title]');
      return Boolean(title && title.textContent && title.textContent.includes(expectedCaseCode));
    }, caseCode);

    const modalCopy = await page.locator('[data-dmca-modal-body]').textContent();

    if (!String(modalCopy || '').includes('Submit counter notice')) {
      throw new Error(`Open DMCA case should render the counter-notice form. Received: ${modalCopy || '(empty)'}`);
    }

    await page.fill('[name="full_name"]', 'Browser QA');
    await page.fill('[name="email"]', 'browser-qa@example.com');
    await page.fill('[name="phone"]', '+1 555 333 4444');
    await page.fill('[name="address_line"]', '45 Browser Lane');
    await page.fill('[name="city"]', 'Playwright');
    await page.fill('[name="country"]', 'US');
    await page.fill('[name="postal_code"]', '10001');
    await page.fill('[name="removed_material_location"]', 'https://127.0.0.1/d/dmcabrowser01');
    await page.fill('[name="mistake_statement"]', 'I have a good-faith belief that the material was removed due to mistake or misidentification.');
    await page.fill('[name="jurisdiction_statement"]', 'I consent to the jurisdiction of the appropriate Federal District Court and will accept service of process.');
    await page.fill('[name="signature_name"]', 'Browser QA');
    await page.locator('[data-dmca-counter-form] button[type="submit"]').click();

    await page.waitForFunction(() => {
      const modalBody = document.querySelector('[data-dmca-modal-body]');
      const counterTotal = document.querySelector('[data-dmca-counter]');
      return Boolean(
        modalBody &&
        modalBody.textContent &&
        modalBody.textContent.includes('Counter notice') &&
        counterTotal &&
        counterTotal.textContent &&
        counterTotal.textContent.trim() === '1'
      );
    });

    await page.waitForFunction(() => {
      const list = document.querySelector('[data-dmca-list]');
      return Boolean(list && list.textContent && list.textContent.includes('Counter notice sent'));
    });

    const updatedStatus = await page.locator('[data-dmca-list]').textContent();

    if (!String(updatedStatus || '').includes('Counter notice sent')) {
      throw new Error(`DMCA list should update after counter-notice submission. Received: ${updatedStatus || '(empty)'}`);
    }

    await activeSession.context.close();
  } finally {
    await browser.close();
  }

  process.stdout.write('dmca browser smoke ok\n');
})().catch((error) => {
  process.stderr.write(String(error && error.stack ? error.stack : error) + '\n');
  process.exit(1);
});
