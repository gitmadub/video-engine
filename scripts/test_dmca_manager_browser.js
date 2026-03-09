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
  const responseCaseCode = requiredEnv('DMCA_BROWSER_RESPONSE_CASE_CODE');
  const deleteCaseCode = requiredEnv('DMCA_BROWSER_DELETE_CASE_CODE');
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

    if (!String(emptyCopy || '').includes('No DMCA complaints right now.')) {
      throw new Error(`Empty-state user should see the DMCA empty copy. Received: ${emptyCopy || '(empty)'}`);
    }

    if (emptyOpen !== '0') {
      throw new Error(`Empty-state user should have zero open cases. Received: ${emptyOpen || '(empty)'}`);
    }

    await emptySession.context.close();

    const activeSession = await loginAndOpenDmcaPage(browser, baseURL, username, password);
    const page = activeSession.page;

    await page.waitForFunction(() => {
      const shell = document.querySelector('.sidebar.settings-page');
      const content = document.querySelector('.content_box.settings_data');
      return Boolean(shell && content);
    });

    await page.waitForFunction(() => {
      const openCases = document.querySelector('[data-dmca-open]');
      const pendingDelete = document.querySelector('[data-dmca-pending-delete]');
      const deleted = document.querySelector('[data-dmca-deleted]');

      return Boolean(
        openCases &&
        pendingDelete &&
        deleted &&
        openCases.textContent &&
        pendingDelete.textContent &&
        deleted.textContent &&
        openCases.textContent.trim() === '2' &&
        pendingDelete.textContent.trim() === '2' &&
        deleted.textContent.trim() === '1'
      );
    });

    await page.locator('.settings_menu a[href="#dmca_cases"]').click();
    await page.waitForFunction(() => {
      const panel = document.querySelector('#dmca_cases');
      return Boolean(panel && panel.classList.contains('active'));
    });

    await page.waitForFunction((expectedCaseCode) => {
      const list = document.querySelector('[data-dmca-list]');
      return Boolean(list && list.textContent && list.textContent.includes(expectedCaseCode));
    }, responseCaseCode);

    await page.locator(`[data-dmca-view="${responseCaseCode}"]`).first().click();
    await page.waitForFunction((expectedCaseCode) => {
      const title = document.querySelector('[data-dmca-modal-title]');
      return Boolean(title && title.textContent && title.textContent.includes(expectedCaseCode));
    }, responseCaseCode);

    const modalCopy = await page.locator('[data-dmca-modal-body]').textContent();

    if (!String(modalCopy || '').includes('Optional uploader response')) {
      throw new Error(`Open DMCA case should render the optional uploader response form. Received: ${modalCopy || '(empty)'}`);
    }

    await page.locator('[data-dmca-response-form] button[type="submit"]').click();

    await page.waitForFunction(() => {
      const modalBody = document.querySelector('[data-dmca-modal-body]');
      const responseTotal = document.querySelector('[data-dmca-response]');
      return Boolean(
        modalBody &&
        modalBody.textContent &&
        modalBody.textContent.includes('Uploader response') &&
        responseTotal &&
        responseTotal.textContent &&
        responseTotal.textContent.trim() === '1'
      );
    });

    await page.waitForFunction((expectedCaseCode) => {
      const list = document.querySelector('[data-dmca-list]');
      return Boolean(list && list.textContent && list.textContent.includes(expectedCaseCode) && list.textContent.includes('Info sent'));
    }, responseCaseCode);

    await page.locator('#dmca-case-modal .close').click();
    await page.waitForFunction(() => {
      const modal = document.querySelector('#dmca-case-modal');
      return Boolean(modal && !modal.classList.contains('show'));
    });

    page.once('dialog', (dialog) => dialog.accept());
    await page.locator(`[data-dmca-delete-case="${deleteCaseCode}"]`).first().click();

    await page.waitForFunction(() => {
      const openCases = document.querySelector('[data-dmca-open]');
      const deleted = document.querySelector('[data-dmca-deleted]');
      return Boolean(
        openCases &&
        deleted &&
        openCases.textContent &&
        deleted.textContent &&
        openCases.textContent.trim() === '1' &&
        deleted.textContent.trim() === '2'
      );
    });

    await page.waitForFunction((expectedCaseCode) => {
      const list = document.querySelector('[data-dmca-list]');
      return Boolean(
        list &&
        list.textContent &&
        list.textContent.includes(expectedCaseCode) &&
        list.textContent.includes('Deleted by you')
      );
    }, deleteCaseCode);

    const updatedList = await page.locator('[data-dmca-list]').textContent();

    if (!String(updatedList || '').includes('Deleted by you')) {
      throw new Error(`DMCA list should update after direct deletion. Received: ${updatedList || '(empty)'}`);
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
