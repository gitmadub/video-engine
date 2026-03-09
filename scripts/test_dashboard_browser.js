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
    }, { username, password, token, loginUrl: appPath('/api/auth/login') });

    if (loginResult.status !== 200 || loginResult.payload.status !== 'redirect') {
      throw new Error(`Login failed: ${JSON.stringify(loginResult)}`);
    }

    async function requestJson(path, method = 'GET', form = null) {
      return page.evaluate(async (request) => {
        const headers = {
          Accept: 'application/json',
        };
        const options = {
          method: request.method,
          credentials: 'same-origin',
          headers,
        };

        if (request.method !== 'GET' && request.form) {
          headers['Content-Type'] = 'application/x-www-form-urlencoded; charset=UTF-8';
          options.body = new URLSearchParams(request.form).toString();
        }

        const response = await fetch(request.path, options);
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
      }, {
        path: appPath(path),
        method,
        form,
      });
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

    await page.goto(appPath('/premium-plans'), { waitUntil: 'networkidle' });
    const premiumRoot = page.locator('.my-premium');

    if ((await premiumRoot.count()) === 0) {
      const html = await page.content();
      throw new Error(`Premium page root was not rendered on ${page.url()}\n${html.slice(0, 1200)}`);
    }

    await page.waitForFunction(() => {
      const root = document.querySelector('.my-premium');
      return Boolean(window.__vePremiumCheckoutModal && root && root.__vue__ && root.__vue__.__vePremiumCheckoutBound);
    });

    const premiumToken = await page.evaluate(() => window.VE_CSRF_TOKEN || '');

    if (!premiumToken) {
      throw new Error('Unable to extract the premium page CSRF token after login.');
    }

    const summaryBefore = await requestJson('/api/premium/summary');

    if (summaryBefore.status !== 200 || summaryBefore.payload.status !== 'ok') {
      throw new Error(`Premium summary API did not respond successfully before checkout: ${JSON.stringify(summaryBefore)}`);
    }

    if (Number(summaryBefore.payload.summary && summaryBefore.payload.summary.used_bw) !== 0) {
      throw new Error(`Regular dashboard traffic should not be counted as premium bandwidth usage. Received: ${JSON.stringify(summaryBefore.payload.summary || null)}`);
    }

    const premiumCardTitles = await page.locator('[data-premium-overview] [data-premium-card-title]').allTextContents();
    const expectedCardTitles = ['Premium account', 'Premium bandwidth', 'Premium bandwidth usage'];

    if (premiumCardTitles.join('|') !== expectedCardTitles.join('|')) {
      throw new Error(`Premium overview cards did not render in the expected order. Received: ${JSON.stringify(premiumCardTitles)}`);
    }

    const balanceBefore = Number(summaryBefore.payload.summary && summaryBefore.payload.summary.balance_micro_usd);

    if (!Number.isFinite(balanceBefore) || balanceBefore <= 0) {
      throw new Error(`Premium summary balance should be positive before checkout. Received: ${JSON.stringify(summaryBefore.payload.summary || null)}`);
    }

    const quoteResponse = await requestJson('/api/billing/quote', 'POST', {
      token: premiumToken,
      purchase_type: 'account',
      package_id: 'monthly',
      payment_method: 'balance',
    });

    if (quoteResponse.status !== 200 || quoteResponse.payload.status !== 'ok') {
      throw new Error(`Premium quote API did not respond successfully: ${JSON.stringify(quoteResponse)}`);
    }

    const checkoutQuote = quoteResponse.payload.checkout || {};
    const monthlyCharge = Number(checkoutQuote.amount_micro_usd);

    if (!checkoutQuote.can_pay || !Number.isFinite(monthlyCharge) || monthlyCharge <= 0) {
      throw new Error(`Monthly balance quote should be payable with a positive amount. Received: ${JSON.stringify(checkoutQuote)}`);
    }

    const monthlyPlan = page.locator('.premium-plans ul.packages-list > li.p-plan').first();
    await monthlyPlan.scrollIntoViewIfNeeded();
    await monthlyPlan.click();

    const accountPayButton = page.locator('.row.premium-plans .btn.btn-primary').first();
    await accountPayButton.click();

    await page.waitForFunction(() => {
      const title = document.querySelector('.ve-premium-modal.is-open .ve-premium-modal-title');
      return Boolean(title && title.textContent && title.textContent.includes('Monthly - Plan - Balance payment'));
    });

    if (await page.locator('#view.show, #pay_plan.show').count() !== 0) {
      throw new Error('Legacy premium balance modals should remain hidden when the runtime checkout modal is active.');
    }

    await page.getByRole('button', { name: 'Confirm purchase' }).click();

    await page.waitForFunction(() => {
      const title = document.querySelector('.ve-premium-modal.is-open .ve-premium-modal-title');
      return Boolean(title && title.textContent && title.textContent.includes('Purchase completed'));
    });

    const purchaseNotice = (await page.locator('.ve-premium-modal.is-open .ve-premium-modal-notice').textContent() || '').trim();

    if (!purchaseNotice.includes('Premium account active until')) {
      throw new Error(`Balance purchase success notice should describe the premium activation window. Received: ${purchaseNotice || '(empty)'}`);
    }

    const summaryAfterBalance = await requestJson('/api/premium/summary');

    if (summaryAfterBalance.status !== 200 || summaryAfterBalance.payload.status !== 'ok') {
      throw new Error(`Premium summary API did not respond successfully after balance checkout: ${JSON.stringify(summaryAfterBalance)}`);
    }

    const postBalanceSummary = summaryAfterBalance.payload.summary || {};
    const balanceAfter = Number(postBalanceSummary.balance_micro_usd);

    if (postBalanceSummary.plan_label !== 'Premium active') {
      throw new Error(`Premium summary did not reflect the purchased premium account. Received: ${JSON.stringify(postBalanceSummary)}`);
    }

    if (balanceAfter !== balanceBefore - monthlyCharge) {
      throw new Error(`Premium balance did not decrease by the quoted monthly charge. Before=${balanceBefore}, charge=${monthlyCharge}, after=${balanceAfter}`);
    }

    await page.getByRole('button', { name: 'Done' }).click();
    await page.waitForFunction(() => !document.querySelector('.ve-premium-modal.is-open'));

    const bitcoinLabel = page.locator('label[for="payment_bw_1"]');
    await bitcoinLabel.scrollIntoViewIfNeeded();
    await bitcoinLabel.click();

    const bandwidthPayButton = page.locator('.bandwidth-plans .btn.btn-primary').first();
    await bandwidthPayButton.click();

    await page.waitForFunction(() => {
      const title = document.querySelector('.ve-premium-modal.is-open .ve-premium-modal-title');
      const qr = document.querySelector('.ve-premium-modal.is-open img[alt="Crypto payment QR"]');
      return Boolean(title && title.textContent && title.textContent.includes('Bitcoin payment') && qr);
    });

    if (await page.locator('#pay_bw.show').count() !== 0) {
      throw new Error('Legacy premium crypto modals should remain hidden when the runtime checkout modal is active.');
    }

    const cryptoNotice = (await page.locator('.ve-premium-modal.is-open .ve-premium-modal-notice').textContent() || '').trim();
    const cryptoAddress = (await page.locator('.ve-premium-modal.is-open .ve-premium-address-block').textContent() || '').trim();

    if (!cryptoNotice.includes('Sandbox crypto invoice created')) {
      throw new Error(`Crypto checkout notice should confirm sandbox invoice creation. Received: ${cryptoNotice || '(empty)'}`);
    }

    if (!cryptoAddress || cryptoAddress.length < 16) {
      throw new Error(`Crypto checkout should render a wallet address in the runtime modal. Received: ${cryptoAddress || '(empty)'}`);
    }
  } finally {
    await browser.close();
  }

  process.stdout.write('browser smoke ok\n');
})().catch((error) => {
  process.stderr.write(String(error && error.stack ? error.stack : error) + '\n');
  process.exit(1);
});
