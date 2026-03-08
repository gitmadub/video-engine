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

(async () => {
  const baseURL = requiredEnv('DASHBOARD_BROWSER_BASE_URL');
  const pathPrefix = (process.env.DASHBOARD_BROWSER_PATH_PREFIX || '').replace(/^\/+|\/+$/g, '');
  const username = requiredEnv('DASHBOARD_BROWSER_USER');
  const password = requiredEnv('DASHBOARD_BROWSER_PASSWORD');
  const reportFrom = requiredEnv('DASHBOARD_BROWSER_FROM');
  const reportTo = requiredEnv('DASHBOARD_BROWSER_TO');
  const expectedViews = requiredEnv('DASHBOARD_BROWSER_EXPECTED_VIEWS');
  const expectedTopTitle = requiredEnv('DASHBOARD_BROWSER_TOP_TITLE');
  const browserPath = firstExistingPath([
    process.env.DASHBOARD_BROWSER_EXECUTABLE || '',
    'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe',
    'C:\\Program Files (x86)\\Google\\Chrome\\Application\\chrome.exe',
    'C:\\Program Files\\Microsoft\\Edge\\Application\\msedge.exe',
    'C:\\Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe',
  ]);

  if (!browserPath) {
    throw new Error('Unable to find a Chromium-based browser executable for Playwright.');
  }

  function appPath(path) {
    if (!path) {
      return pathPrefix ? `/${pathPrefix}` : '/';
    }

    if (/^(?:[a-z][a-z0-9+.-]*:)?\/\//i.test(path)) {
      return path;
    }

    if (!path.startsWith('/')) {
      path = `/${path}`;
    }

    return pathPrefix ? `/${pathPrefix}${path}` : path;
  }

  const browser = await chromium.launch({
    headless: true,
    executablePath: browserPath,
  });

  try {
    const context = await browser.newContext({ baseURL });
    const page = await context.newPage();
    await page.goto(appPath('/'), { waitUntil: 'networkidle' });

    const token = await page.evaluate(() => window.VE_CSRF_TOKEN || '');

    if (!token) {
      throw new Error('Unable to extract the runtime CSRF token from the page context.');
    }

    const loginResult = await page.evaluate(async (credentials) => {
      const response = await fetch(credentials.loginUrl, {
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
    }, { username, password, token, loginUrl: appPath('/login') });

    if (loginResult.status !== 200 || loginResult.payload.status !== 'redirect') {
      throw new Error(`Login failed: ${JSON.stringify(loginResult)}`);
    }

    await page.goto(appPath('/dashboard'), { waitUntil: 'networkidle' });
    const dashboardRoot = page.locator('[data-dashboard-home]');

    if ((await dashboardRoot.count()) === 0) {
      const html = await page.content();
      throw new Error(`Dashboard root was not rendered on ${page.url()}\n${html.slice(0, 1200)}`);
    }

    await page.waitForFunction(
      (title) => {
        const root = document.querySelector('[data-dashboard-top-files]');
        return Boolean(root && root.textContent && root.textContent.includes(title));
      },
      expectedTopTitle
    );

    const balance = (await page.locator('[data-dashboard-balance]').textContent() || '').trim();

    if (!balance || balance === '$0.00000') {
      throw new Error(`Dashboard balance did not update from the live API. Received: ${balance || '(empty)'}`);
    }

    await page.goto(appPath(`/dashboard/reports?from=${reportFrom}&to=${reportTo}`), { waitUntil: 'networkidle' });
    const reportsRoot = page.locator('[data-dashboard-reports]');

    if ((await reportsRoot.count()) === 0) {
      const html = await page.content();
      throw new Error(`Reports root was not rendered on ${page.url()}\n${html.slice(0, 1200)}`);
    }

    await page.waitForFunction(() => {
      const totals = document.querySelector('[data-reports-total-views]');
      return Boolean(totals && totals.textContent && totals.textContent.trim() !== '0');
    });

    const totalViews = (await page.locator('[data-reports-total-views]').textContent() || '').trim();

    if (totalViews !== expectedViews) {
      throw new Error(`Unexpected reports total views. Expected ${expectedViews}, received ${totalViews || '(empty)'}`);
    }
  } finally {
    await browser.close();
  }

  process.stdout.write('browser smoke ok\n');
})().catch((error) => {
  process.stderr.write(String(error && error.stack ? error.stack : error) + '\n');
  process.exit(1);
});
