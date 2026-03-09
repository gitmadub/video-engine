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

function optionalNumber(name, fallback) {
  const value = process.env[name];

  if (value === undefined || value === null || value === '') {
    return fallback;
  }

  const parsed = Number(value);

  if (!Number.isFinite(parsed)) {
    throw new Error(`Environment variable ${name} must be numeric.`);
  }

  return parsed;
}

function appPath(pathname, pathPrefix) {
  if (!pathname) {
    return pathPrefix ? `/${pathPrefix}` : '/';
  }

  if (/^(?:[a-z][a-z0-9+.-]*:)?\/\//i.test(pathname)) {
    return pathname;
  }

  const normalized = pathname.startsWith('/') ? pathname : `/${pathname}`;
  return pathPrefix ? `/${pathPrefix}${normalized}` : normalized;
}

async function loginWithApi(context, baseURL, pathPrefix, username, password) {
  const page = await context.newPage();
  await page.goto(appPath('/', pathPrefix), { waitUntil: 'networkidle' });

  const csrf = await page.evaluate(() => window.VE_CSRF_TOKEN || '');

  if (!csrf) {
    throw new Error('Unable to extract CSRF token for login.');
  }

  const result = await page.evaluate(async (payload) => {
    const response = await fetch(payload.loginUrl, {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        Accept: 'application/json',
        'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
      },
      body: new URLSearchParams({
        login: payload.username,
        password: payload.password,
        token: payload.csrf,
      }).toString(),
    });

    let body = {};

    try {
      body = await response.json();
    } catch (error) {
      body = {};
    }

    return {
      status: response.status,
      body,
    };
  }, {
    username,
    password,
    csrf,
    loginUrl: appPath('/api/auth/login', pathPrefix),
  });

  if (result.status !== 200 || result.body.status !== 'redirect') {
    throw new Error(`Login failed: ${JSON.stringify(result)}`);
  }

  await page.close();
}

async function fetchHtmlStatus(page, url, options = {}) {
  return page.evaluate(async (request) => {
    const response = await fetch(request.url, request.options || {});
    return {
      status: response.status,
      body: await response.text(),
    };
  }, {
    url,
    options,
  });
}

async function fetchBinaryStatus(page, url, form) {
  return page.evaluate(async (request) => {
    const response = await fetch(request.url, {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
      },
      body: new URLSearchParams(request.form).toString(),
    });
    const buffer = await response.arrayBuffer();

    return {
      status: response.status,
      bytes: buffer.byteLength,
      contentType: response.headers.get('content-type') || '',
    };
  }, {
    url,
    form,
  });
}

async function extractInlineStringVariable(page, variableName) {
  const html = await page.content();
  const pattern = new RegExp(`var\\s+${variableName}\\s*=\\s*(\"(?:\\\\.|[^\\\\\"])*\"|'(?:\\\\.|[^\\\\'])*')`);
  const match = html.match(pattern);

  if (!match || !match[1]) {
    return '';
  }

  if (match[1][0] === '"') {
    return JSON.parse(match[1]);
  }

  return match[1].slice(1, -1).replace(/\\'/g, "'");
}

async function elementDocumentBox(page, selector) {
  return page.locator(selector).evaluate((element) => {
    const rect = element.getBoundingClientRect();

    return {
      x: rect.left + window.scrollX,
      y: rect.top + window.scrollY,
      width: rect.width,
      height: rect.height,
    };
  });
}

(async () => {
  const baseURL = requiredEnv('VIDEO_DOWNLOAD_BROWSER_BASE_URL');
  const watchUrl = requiredEnv('VIDEO_DOWNLOAD_BROWSER_WATCH_URL');
  const embedUrl = requiredEnv('VIDEO_DOWNLOAD_BROWSER_EMBED_URL');
  const username = requiredEnv('VIDEO_DOWNLOAD_BROWSER_USER');
  const password = requiredEnv('VIDEO_DOWNLOAD_BROWSER_PASSWORD');
  const publicId = requiredEnv('VIDEO_DOWNLOAD_BROWSER_PUBLIC_ID');
  const pathPrefix = (process.env.VIDEO_DOWNLOAD_BROWSER_PATH_PREFIX || '').replace(/^\/+|\/+$/g, '');
  const waitFree = optionalNumber('VIDEO_DOWNLOAD_BROWSER_WAIT_FREE', 15);
  const waitPremium = optionalNumber('VIDEO_DOWNLOAD_BROWSER_WAIT_PREMIUM', 0);
  const skipPremium = process.env.VIDEO_DOWNLOAD_BROWSER_SKIP_PREMIUM === '1';
  const browserPath = firstExistingPath([
    process.env.VIDEO_DOWNLOAD_BROWSER_EXECUTABLE || '',
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
    const anonymousContext = await browser.newContext({
      baseURL,
    });
    const freePage = await anonymousContext.newPage();
    await freePage.goto(watchUrl, { waitUntil: 'networkidle' });

    await freePage.waitForSelector('#ve-download-button');

    if ((await freePage.locator('.own-file').count()) !== 0) {
      throw new Error('Anonymous watch page should not render the owner notice.');
    }

    if ((await freePage.locator('#pills-tab').count()) !== 0) {
      throw new Error('Anonymous watch page should not render owner export tabs.');
    }

    const freeStatusText = ((await freePage.locator('#ve-download-status').textContent()) || '').trim();

    if (!freeStatusText.includes(`Free download unlocks after ${waitFree} seconds.`)) {
      throw new Error(`Unexpected free download status text: ${freeStatusText}`);
    }

    const pageHtml = await freePage.content();

    if (pageHtml.includes(`/download/${publicId}/t/`)) {
      throw new Error('Watch page HTML should not expose tokenized download URLs.');
    }

    const freeButtonBoxBefore = await elementDocumentBox(freePage, '#ve-download-button');

    if (!freeButtonBoxBefore) {
      throw new Error('Unable to measure the free download button before activation.');
    }

    const freeCsrfToken = await extractInlineStringVariable(freePage, 'downloadCsrfToken');

    if (!freeCsrfToken) {
      throw new Error('Unable to extract CSRF token from the free watch page.');
    }

    const freeResolvePromise = freePage.waitForResponse((response) => {
      return response.url().includes(`/api/videos/${publicId}/download/resolve`) && response.request().method() === 'POST';
    }, { timeout: (waitFree + 15) * 1000 });

    const freeStart = Date.now();
    await freePage.locator('#ve-download-button').click();
    const freeResolveResponse = await freeResolvePromise;
    const freeResolveBody = await freeResolveResponse.json();
    const freeElapsedSeconds = (Date.now() - freeStart) / 1000;

    if (freeResolveBody.status !== 'ok' || freeResolveBody.ready !== true || !freeResolveBody.download_token || !freeResolveBody.download_action) {
      throw new Error(`Free download resolve did not return a ready protected token: ${JSON.stringify(freeResolveBody)}`);
    }

    if (freeElapsedSeconds < waitFree || freeElapsedSeconds > waitFree + 8) {
      throw new Error(`Free download wait time was unexpected. Expected about ${waitFree}s, observed ${freeElapsedSeconds.toFixed(2)}s.`);
    }

    await freePage.waitForFunction(() => {
      const button = document.getElementById('ve-download-button');
      return Boolean(button && button.getAttribute('data-ready') === '1');
    }, null, { timeout: 10000 });

    const freeButtonBoxAfter = await elementDocumentBox(freePage, '#ve-download-button');

    if (!freeButtonBoxAfter) {
      throw new Error('Unable to measure the free download button after activation.');
    }

    const freeMovementX = Math.abs(freeButtonBoxBefore.x - freeButtonBoxAfter.x);
    const freeMovementY = Math.abs(freeButtonBoxBefore.y - freeButtonBoxAfter.y);

    if (freeMovementX > 1 || freeMovementY > 1) {
      throw new Error(`Download button moved after countdown. dx=${freeMovementX}, dy=${freeMovementY}`);
    }

    if ((await freePage.locator('#ve-download-meta').getAttribute('hidden')) !== null) {
      throw new Error('Protected download meta block should be visible after the token is issued.');
    }

    const freeDownloadResult = await fetchBinaryStatus(freePage, freeResolveBody.download_action, {
      token: freeCsrfToken,
      download_token: freeResolveBody.download_token,
    });

    if (freeDownloadResult.status !== 200 || freeDownloadResult.bytes <= 0) {
      throw new Error(`Protected free download failed. status=${freeDownloadResult.status}, bytes=${freeDownloadResult.bytes}`);
    }

    const freeReplay = await fetchHtmlStatus(freePage, freeResolveBody.download_action, {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
      },
      body: new URLSearchParams({
        token: freeCsrfToken,
        download_token: freeResolveBody.download_token,
      }).toString(),
    });

    if (freeReplay.status !== 403 || !freeReplay.body.includes('invalid or has expired')) {
      throw new Error(`Reusing the same protected free download token should fail with 403. Received ${freeReplay.status}.`);
    }

    const noSessionContext = await browser.newContext({ baseURL });
    const noSessionPage = await noSessionContext.newPage();
    await noSessionPage.goto(watchUrl, { waitUntil: 'networkidle' });
    const noSessionCsrf = await extractInlineStringVariable(noSessionPage, 'downloadCsrfToken');
    const noSessionReplay = await fetchHtmlStatus(noSessionPage, freeResolveBody.download_action, {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
      },
      body: new URLSearchParams({
        token: noSessionCsrf,
        download_token: freeResolveBody.download_token,
      }).toString(),
    });

    if (noSessionReplay.status !== 403) {
      throw new Error(`Protected download token should not work from a different session. Received ${noSessionReplay.status}.`);
    }

    await noSessionPage.close();
    await noSessionContext.close();

    const legacyRouteResponse = await fetchHtmlStatus(freePage, appPath(`/download/${publicId}/o/test.mp4`, pathPrefix));

    if (legacyRouteResponse.status !== 404) {
      throw new Error(`Legacy predictable download route should return 404. Received ${legacyRouteResponse.status}.`);
    }

    const embedPage = await anonymousContext.newPage();
    await embedPage.goto(embedUrl, { waitUntil: 'networkidle' });
    await embedPage.waitForSelector('#ve-secure-player', { timeout: 15000 });

    if ((await embedPage.locator('.ve-embed-title').count()) !== 0) {
      throw new Error('Embed player should not render the extra title bar above or below the real player.');
    }

    await embedPage.waitForFunction(() => {
      const state = document.getElementById('ve-player-state');
      return Boolean(state && !state.classList.contains('is-visible'));
    }, null, { timeout: 15000 });

    await embedPage.close();
    await freePage.close();
    await anonymousContext.close();

    if (!skipPremium) {
      const premiumContext = await browser.newContext({
        baseURL,
      });

      await loginWithApi(premiumContext, baseURL, pathPrefix, username, password);

      const premiumPage = await premiumContext.newPage();
      await premiumPage.goto(watchUrl, { waitUntil: 'networkidle' });

      if ((await premiumPage.locator('.own-file').count()) === 0) {
        throw new Error('Owner/premium watch page should render the own-file notice.');
      }

      if ((await premiumPage.locator('#pills-tab').count()) === 0) {
        throw new Error('Owner/premium watch page should render export tabs.');
      }

      const premiumStatusText = ((await premiumPage.locator('#ve-download-status').textContent()) || '').trim();

      if (!premiumStatusText.includes('Instant protected download is available')) {
        throw new Error(`Unexpected premium status text: ${premiumStatusText}`);
      }

      const premiumCsrfToken = await extractInlineStringVariable(premiumPage, 'downloadCsrfToken');
      const premiumStart = Date.now();
      const premiumRequestBody = await premiumPage.evaluate(async (request) => {
        const response = await fetch(request.url, {
          method: 'POST',
          credentials: 'same-origin',
          headers: {
            Accept: 'application/json',
            'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
          },
          body: new URLSearchParams({
            token: request.csrf,
          }).toString(),
        });

        return response.json();
      }, {
        url: appPath(`/api/videos/${publicId}/download/request`, pathPrefix),
        csrf: premiumCsrfToken,
      });
      const premiumElapsedSeconds = (Date.now() - premiumStart) / 1000;

      if (premiumRequestBody.status !== 'ok' || premiumRequestBody.ready !== true || !premiumRequestBody.download_token || !premiumRequestBody.download_action) {
        throw new Error(`Premium protected download did not return an instant token: ${JSON.stringify(premiumRequestBody)}`);
      }

      if (premiumElapsedSeconds > Math.max(5, waitPremium + 5)) {
        throw new Error(`Premium protected download should be effectively instant. Observed ${premiumElapsedSeconds.toFixed(2)}s.`);
      }

      const premiumDownloadResult = await fetchBinaryStatus(premiumPage, premiumRequestBody.download_action, {
        token: premiumCsrfToken,
        download_token: premiumRequestBody.download_token,
      });

      if (premiumDownloadResult.status !== 200 || premiumDownloadResult.bytes <= 0) {
        throw new Error(`Protected premium download failed. status=${premiumDownloadResult.status}, bytes=${premiumDownloadResult.bytes}`);
      }

      const premiumReplay = await fetchHtmlStatus(premiumPage, premiumRequestBody.download_action, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
          'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
        },
        body: new URLSearchParams({
          token: premiumCsrfToken,
          download_token: premiumRequestBody.download_token,
        }).toString(),
      });

      if (premiumReplay.status !== 403) {
        throw new Error(`Reusing the same premium download token should fail with 403. Received ${premiumReplay.status}.`);
      }

      await premiumPage.close();
      await premiumContext.close();
    }
  } finally {
    await browser.close();
  }

  process.stdout.write('video download browser qa ok\n');
})().catch((error) => {
  process.stderr.write(String(error && error.stack ? error.stack : error) + '\n');
  process.exit(1);
});
