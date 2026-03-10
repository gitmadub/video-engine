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

function pulseIntervalFor(minWatchSeconds) {
  const safeMinimum = Math.max(5, Number(minWatchSeconds) || 0);
  return Math.max(5, Math.min(10, Math.floor(safeMinimum / 3)));
}

function requiredPulseCountFor(minWatchSeconds) {
  const safeMinimum = Math.max(5, Number(minWatchSeconds) || 0);
  const interval = pulseIntervalFor(safeMinimum);
  return Math.max(1, Math.min(6, Math.floor(Math.max(1, safeMinimum - 1) / Math.max(1, interval))));
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

async function fetchJsonStatus(page, url, options = {}) {
  return page.evaluate(async (request) => {
    const response = await fetch(request.url, request.options || {});
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
  const minWatchSeconds = optionalNumber('VIDEO_DOWNLOAD_BROWSER_MIN_WATCH_SECONDS', 30);
  const qualificationTimeoutMs = (minWatchSeconds + 30) * 1000;
  const skipPremium = process.env.VIDEO_DOWNLOAD_BROWSER_SKIP_PREMIUM === '1';
  const debugPlayback = process.env.VIDEO_DOWNLOAD_BROWSER_DEBUG === '1';
  if (debugPlayback) {
    console.log('[qa-debug] playback diagnostics enabled');
  }
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

  const debugPage = (page, label) => {
    if (!debugPlayback) {
      return;
    }

    page.on('console', (message) => {
      console.log(`[${label}] console ${message.type()}: ${message.text()}`);
    });
    page.on('pageerror', (error) => {
      console.log(`[${label}] pageerror: ${error.message}`);
    });
    page.on('request', (request) => {
      if (request.url().includes('/stream/') || request.url().includes('/playback/')) {
        console.log(`[${label}] request ${request.method()} ${request.url()}`);
      }
    });
    page.on('response', async (response) => {
      if (!response.url().includes('/stream/') && !response.url().includes('/playback/')) {
        return;
      }

      let body = '';

      try {
        body = await response.text();
      } catch (error) {
        body = '';
      }

      console.log(`[${label}] response ${response.status()} ${response.url()} ${body.slice(0, 300)}`);
    });
  };

  try {
    const anonymousContext = await browser.newContext({
      baseURL,
    });
    const freePage = await anonymousContext.newPage();
    debugPage(freePage, 'watch');
    await freePage.goto(watchUrl, { waitUntil: 'domcontentloaded' });

    await freePage.waitForSelector('#ve-download-button');

    if ((await freePage.locator('.own-file').count()) !== 0) {
      throw new Error('Anonymous watch page should not render the owner notice.');
    }

    if ((await freePage.locator('#pills-tab').count()) !== 0) {
      throw new Error('Anonymous watch page should not render owner export tabs.');
    }

    const freeStatusText = ((await freePage.locator('#ve-download-status').textContent()) || '').trim();
    const sizeText = ((await freePage.locator('.title-wrap .size').textContent()) || '').trim();

    if (!freeStatusText.includes(`Free download unlocks after ${waitFree} seconds.`)) {
      throw new Error(`Unexpected free download status text: ${freeStatusText}`);
    }

    if (!/\bMB\b/.test(sizeText)) {
      throw new Error(`Video size should be displayed in MB for large values. Received: ${sizeText}`);
    }

    const pageHtml = await freePage.content();

    if (pageHtml.includes(`/download/${publicId}/t/`)) {
      throw new Error('Watch page HTML should not expose tokenized download URLs.');
    }

    const freeButtonBoxBefore = await elementDocumentBox(freePage, '#ve-download-button');

    if (!freeButtonBoxBefore) {
      throw new Error('Unable to measure the free download button before activation.');
    }

    const freeButtonStyle = await freePage.locator('#ve-download-button').evaluate((element) => {
      const styles = window.getComputedStyle(element);
      return {
        display: styles.display,
        alignItems: styles.alignItems,
        justifyContent: styles.justifyContent,
      };
    });

    if (!String(freeButtonStyle.display || '').includes('flex') || freeButtonStyle.alignItems !== 'center' || freeButtonStyle.justifyContent !== 'center') {
      throw new Error(`Download button should use centered flex layout. Received: ${JSON.stringify(freeButtonStyle)}`);
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
    debugPage(noSessionPage, 'no-session');
    await noSessionPage.goto(watchUrl, { waitUntil: 'domcontentloaded' });
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
    debugPage(embedPage, 'embed');
    const prePlayNetwork = {
      poster: 0,
      previewVtt: 0,
      previewJpg: 0,
      key: 0,
      manifest: 0,
      segment: 0,
    };
    const prePlayPlaybackCalls = [];
    const pulsePlaybackTokens = [];
    let playbackTriggeredAt = 0;
    let startupSegmentRequests = 0;
    let secondSegmentRequests = 0;
    let postPlayManifestRequests = 0;
    let postPlayKeyRequests = 0;
    let replayedStartupSegmentRequests = 0;
    let warmedStartupSegmentUrl = '';
    embedPage.on('request', (request) => {
      const url = request.url();

      if (!url.includes('/stream/') && !url.includes('/playback/')) {
        return;
      }

      if (playbackTriggeredAt === 0 && url.includes('/playback/')) {
        prePlayPlaybackCalls.push({
          method: request.method(),
          url,
          body: request.postData() || '',
        });
      }

      if (url.includes('/playback/pulse') && request.method() === 'POST') {
        const body = request.postData() || '';
        const params = new URLSearchParams(body);
        pulsePlaybackTokens.push(params.get('playback_token') || '');
        return;
      }

      const isPrePlay = playbackTriggeredAt === 0;

      if (url.includes('/poster.jpg')) {
        if (isPrePlay) {
          prePlayNetwork.poster += 1;
        }
        return;
      }

      if (url.includes('/preview.vtt')) {
        if (isPrePlay) {
          prePlayNetwork.previewVtt += 1;
        }
        return;
      }

      if (url.includes('/preview.jpg')) {
        if (isPrePlay) {
          prePlayNetwork.previewJpg += 1;
        }
        return;
      }

      if (url.includes('/key?token=')) {
        if (isPrePlay) {
          prePlayNetwork.key += 1;
        } else {
          postPlayKeyRequests += 1;
        }
        return;
      }

      if (url.includes('/manifest.m3u8')) {
        if (isPrePlay) {
          prePlayNetwork.manifest += 1;
        } else {
          postPlayManifestRequests += 1;
        }
        return;
      }

      if (url.includes('/segment/')) {
        if (isPrePlay) {
          prePlayNetwork.segment += 1;
        } else if ((Date.now() - playbackTriggeredAt) <= 2500) {
          startupSegmentRequests += 1;
        }

        if (!isPrePlay && /\/segment\/part_00001\.bin(?:$|\?)/.test(url)) {
          secondSegmentRequests += 1;
        }

        if (!isPrePlay && warmedStartupSegmentUrl && url === warmedStartupSegmentUrl) {
          replayedStartupSegmentRequests += 1;
        }
      }
    });
    await embedPage.goto(embedUrl, { waitUntil: 'domcontentloaded' });
    await embedPage.waitForSelector('#ve-secure-player', { timeout: 15000 });
    await embedPage.waitForLoadState('load');
    await embedPage.waitForFunction(() => {
      const debug = window.__VE_SECURE_PLAYER_DEBUG || null;
      return Boolean(
        debug
        && debug.pageLoaded === true
        && debug.manifestPreloaded === true
        && typeof debug.startupSegmentUrl === 'string'
        && debug.startupSegmentUrl.includes('/segment/')
      );
    }, null, { timeout: 10000 });

    const embedAssets = await embedPage.evaluate(() => ({
      scripts: Array.from(document.querySelectorAll('script[src]')).map((node) => node.getAttribute('src') || ''),
      styles: Array.from(document.querySelectorAll('link[rel="stylesheet"]')).map((node) => node.getAttribute('href') || ''),
    }));
    const embedWarmupState = await embedPage.evaluate(() => window.__VE_SECURE_PLAYER_DEBUG || {});
    warmedStartupSegmentUrl = String(embedWarmupState.startupSegmentUrl || '');

    if (embedAssets.scripts.some((url) => /cdn\.jsdelivr\.net/i.test(url)) || embedAssets.styles.some((url) => /cdn\.jsdelivr\.net/i.test(url))) {
      throw new Error(`Secure embed player should not depend on jsDelivr CDN assets. Observed: ${JSON.stringify(embedAssets)}`);
    }

    if (!embedAssets.scripts.some((url) => /\/assets\/vendor\/hls\/hls\.min\.js(?:$|\?)/.test(url))) {
      throw new Error(`Secure embed player should load local hls.js. Observed scripts: ${JSON.stringify(embedAssets.scripts)}`);
    }

    const hasCustomControlBar = await embedPage.locator('#ve-player-controls').count();
    const hasVideoJsShell = await embedPage.locator('#video_player.video-js').count();

    if (hasCustomControlBar !== 1 || hasVideoJsShell !== 1) {
      throw new Error(`Secure embed player should render the custom video-js style shell. Observed shell=${hasVideoJsShell}, controls=${hasCustomControlBar}.`);
    }

    const playerBox = await elementDocumentBox(embedPage, '#video_player');
    const overlayButtonBox = await elementDocumentBox(embedPage, '#ve-player-overlay-button');
    const playerCenterX = playerBox.x + (playerBox.width / 2);
    const playerCenterY = playerBox.y + (playerBox.height / 2);
    const overlayCenterX = overlayButtonBox.x + (overlayButtonBox.width / 2);
    const overlayCenterY = overlayButtonBox.y + (overlayButtonBox.height / 2);

    if (Math.abs(playerCenterX - overlayCenterX) > 4 || Math.abs(playerCenterY - overlayCenterY) > 4) {
      throw new Error(`The big play overlay button should be visually centered. Player=${JSON.stringify(playerBox)} overlay=${JSON.stringify(overlayButtonBox)}.`);
    }

    if ((await embedPage.locator('.ve-embed-title').count()) !== 0) {
      throw new Error('Embed player should not render the extra title bar above or below the real player.');
    }

    await embedPage.waitForFunction(() => {
      const state = document.getElementById('ve-player-state');
      return Boolean(state && !state.classList.contains('is-visible'));
    }, null, { timeout: 15000 });

    const rawPlaybackToken = await extractInlineStringVariable(embedPage, 'sessionToken');

    if (!rawPlaybackToken) {
      throw new Error('The secure embed page should expose the playback token only to the running player bootstrap.');
    }

    if (prePlayNetwork.poster > 1) {
      throw new Error(`Secure poster image should be fetched at most once before playback begins. Observed ${prePlayNetwork.poster}.`);
    }

    if (prePlayNetwork.previewVtt !== 0 || prePlayNetwork.previewJpg !== 0) {
      throw new Error(`Preview thumbnails should stay cold before playback starts. Observed ${JSON.stringify(prePlayNetwork)}.`);
    }

    if (prePlayNetwork.key !== 1) {
      throw new Error(`Secure playback should warm the AES key exactly once before playback starts. Observed ${JSON.stringify(prePlayNetwork)}.`);
    }

    if (prePlayNetwork.segment !== 1) {
      throw new Error(`Secure stream should lazy-preload exactly one startup segment after page load. Observed ${JSON.stringify(prePlayNetwork)}.`);
    }

    if (prePlayNetwork.manifest > 1) {
      throw new Error(`Secure playback should at most warm the manifest once before playback starts. Observed ${JSON.stringify(prePlayNetwork)}.`);
    }

    if (!String(embedWarmupState.startupSegmentUrl || '').includes('/segment/')) {
      throw new Error(`Secure embed warmup state should expose the prefetched startup segment URL. Received: ${JSON.stringify(embedWarmupState)}`);
    }

    if (prePlayPlaybackCalls.length !== 0) {
      throw new Error(`Secure playback APIs should stay idle until playback starts. Observed: ${JSON.stringify(prePlayPlaybackCalls)}.`);
    }

    const forgedQualification = await fetchJsonStatus(embedPage, appPath(`/api/videos/${publicId}/playback/qualify`, pathPrefix), {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        Accept: 'application/json',
        'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
        'X-Playback-Session': rawPlaybackToken,
      },
      body: new URLSearchParams({
        playback_token: rawPlaybackToken,
        watched_seconds: String(minWatchSeconds),
      }).toString(),
    });

    if (forgedQualification.status !== 403) {
      throw new Error(`Directly forging the qualify POST from the embed token should be rejected. Received: ${JSON.stringify(forgedQualification)}`);
    }

    const firstQualifiedViewPromise = embedPage.waitForResponse((response) => {
      return response.url().includes(`/api/videos/${publicId}/playback/qualify`)
        && response.request().method() === 'POST'
        && response.status() === 200;
    }, { timeout: qualificationTimeoutMs });
    const firstFullPlayPromise = embedPage.waitForResponse((response) => {
      return response.url().includes(`/api/videos/${publicId}/playback/full`)
        && response.request().method() === 'POST'
        && response.status() === 200;
    }, { timeout: qualificationTimeoutMs + 45000 });
    await embedPage.evaluate(async () => {
      const video = document.getElementById('ve-secure-player');

      if (!video) {
        return;
      }

      video.muted = true;

      try {
        await video.play();
      } catch (error) {
        // The readiness check below will fail if playback never begins.
      }
    });
    playbackTriggeredAt = Date.now();

    await embedPage.waitForFunction(() => {
      const video = document.getElementById('ve-secure-player');
      return Boolean(video && !video.paused);
    }, null, { timeout: 15000 });

    const firstQualifiedView = await (await firstQualifiedViewPromise).json();

    if (firstQualifiedView.status !== 'ok' || firstQualifiedView.payable !== true || firstQualifiedView.counted !== true) {
      throw new Error(`The first playback qualification should be payable. Received: ${JSON.stringify(firstQualifiedView)}`);
    }

    if (startupSegmentRequests > 2) {
      throw new Error(`Secure playback should only fetch the current segment plus one segment ahead at startup. Observed ${startupSegmentRequests} startup segment requests.`);
    }

    if (secondSegmentRequests < 1) {
      throw new Error(`Secure playback should fetch the second segment after startup. Observed ${secondSegmentRequests} requests for part_00001.bin.`);
    }

    if (postPlayManifestRequests !== 0 || postPlayKeyRequests !== 0 || replayedStartupSegmentRequests !== 0) {
      throw new Error(`Manifest, key, and warmed startup segment should be reused from preload memory on click. Observed manifest=${postPlayManifestRequests}, key=${postPlayKeyRequests}, replayedStartupSegment=${replayedStartupSegmentRequests}.`);
    }

    if (requiredPulseCountFor(minWatchSeconds) > 1) {
      const nonEmptyPulseTokens = pulsePlaybackTokens.filter(Boolean);
      const uniquePulseTokens = new Set(nonEmptyPulseTokens);

      if (uniquePulseTokens.size < 2) {
        throw new Error(`Playback pulses should rotate the playback token on every POST. Observed tokens: ${JSON.stringify(pulsePlaybackTokens)}.`);
      }

      if (uniquePulseTokens.size !== nonEmptyPulseTokens.length) {
        throw new Error(`Playback pulse requests reused a playback token. Observed tokens: ${JSON.stringify(pulsePlaybackTokens)}.`);
      }
    }

    await embedPage.waitForFunction(() => {
      const video = document.getElementById('ve-secure-player');
      return Boolean(video && video.ended);
    }, null, { timeout: qualificationTimeoutMs + 45000 });

    const firstFullPlay = await (await firstFullPlayPromise).json();

    if (firstFullPlay.status !== 'ok') {
      throw new Error(`The first playback should report a full-play completion. Received: ${JSON.stringify(firstFullPlay)}`);
    }

    await embedPage.close();
    await freePage.close();
    await anonymousContext.close();

    const refreshContext = await browser.newContext({ baseURL });
    const refreshEmbedPage = await refreshContext.newPage();
    debugPage(refreshEmbedPage, 'refresh');
    let sessionRefreshRequests = 0;
    refreshEmbedPage.on('request', (request) => {
      if (request.method() === 'POST' && request.url().includes(`/api/videos/${publicId}/playback/session`)) {
        sessionRefreshRequests += 1;
      }
    });
    await refreshEmbedPage.goto(embedUrl, { waitUntil: 'domcontentloaded' });
    await refreshEmbedPage.waitForSelector('#ve-secure-player', { timeout: 15000 });
    await refreshEmbedPage.waitForLoadState('load');
    await refreshEmbedPage.waitForFunction(() => {
      const debug = window.__VE_SECURE_PLAYER_DEBUG || null;
      return Boolean(debug && typeof debug.expireSessionForTest === 'function' && typeof debug.refreshSession === 'function');
    }, null, { timeout: 10000 });
    const forcedExpiry = await refreshEmbedPage.evaluate(() => {
      const debug = window.__VE_SECURE_PLAYER_DEBUG || null;

      if (!debug || typeof debug.expireSessionForTest !== 'function') {
        return false;
      }

      debug.expireSessionForTest();
      return true;
    });

    if (!forcedExpiry) {
      throw new Error('Secure embed QA could not force an expired playback session.');
    }

    await refreshEmbedPage.evaluate(async () => {
      const video = document.getElementById('ve-secure-player');

      if (!video) {
        return;
      }

      video.muted = true;

      try {
        await video.play();
      } catch (error) {
        // The readiness check below will fail if playback never begins.
      }
    });

    await refreshEmbedPage.waitForFunction(() => {
      const video = document.getElementById('ve-secure-player');
      return Boolean(video && !video.paused && video.currentTime > 1);
    }, null, { timeout: 20000 });

    const refreshState = await refreshEmbedPage.evaluate(() => {
      const debug = window.__VE_SECURE_PLAYER_DEBUG || {};
      const state = document.getElementById('ve-player-state');
      return {
        refreshCount: Number(debug.sessionRefreshCount || 0),
        stateVisible: Boolean(state && state.classList.contains('is-visible')),
        stateText: state ? String(state.textContent || '') : '',
      };
    });

    if (sessionRefreshRequests < 1 || refreshState.refreshCount < 1) {
      throw new Error(`Expired playback sessions should refresh automatically before playback starts. Observed requests=${sessionRefreshRequests}, state=${JSON.stringify(refreshState)}.`);
    }

    if (refreshState.stateVisible) {
      throw new Error(`Secure playback should not show an error after refreshing an expired session. Observed: ${JSON.stringify(refreshState)}.`);
    }

    await refreshEmbedPage.close();
    await refreshContext.close();

    const repeatContext = await browser.newContext({ baseURL });
    const repeatEmbedPage = await repeatContext.newPage();
    debugPage(repeatEmbedPage, 'repeat');
    await repeatEmbedPage.goto(embedUrl, { waitUntil: 'domcontentloaded' });
    await repeatEmbedPage.waitForSelector('#ve-secure-player', { timeout: 15000 });
    await repeatEmbedPage.waitForFunction(() => {
      const state = document.getElementById('ve-player-state');
      return Boolean(state && !state.classList.contains('is-visible'));
    }, null, { timeout: 15000 });

    const secondQualifiedViewPromise = repeatEmbedPage.waitForResponse((response) => {
      return response.url().includes(`/api/videos/${publicId}/playback/qualify`)
        && response.request().method() === 'POST'
        && response.status() === 200;
    }, { timeout: qualificationTimeoutMs });

    await repeatEmbedPage.evaluate(async () => {
      const video = document.getElementById('ve-secure-player');

      if (!video) {
        return;
      }

      video.muted = true;

      try {
        await video.play();
      } catch (error) {
        // The readiness check below will fail if playback never begins.
      }
    });

    await repeatEmbedPage.waitForFunction(() => {
      const video = document.getElementById('ve-secure-player');
      return Boolean(video && !video.paused);
    }, null, { timeout: 15000 });

    const secondQualifiedView = await (await secondQualifiedViewPromise).json();

    if (secondQualifiedView.status !== 'ok' || secondQualifiedView.payable !== false || secondQualifiedView.counted !== false) {
      throw new Error(`The second playback from the same viewer should not be payable. Received: ${JSON.stringify(secondQualifiedView)}`);
    }

    await repeatEmbedPage.close();
    await repeatContext.close();

    if (!skipPremium) {
      const premiumContext = await browser.newContext({
        baseURL,
      });

      await loginWithApi(premiumContext, baseURL, pathPrefix, username, password);

      const premiumPage = await premiumContext.newPage();
      debugPage(premiumPage, 'premium');
      await premiumPage.goto(watchUrl, { waitUntil: 'domcontentloaded' });

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

      const reportDate = new Date().toISOString().slice(0, 10);
      const premiumReports = await fetchJsonStatus(premiumPage, appPath(`/api/dashboard/reports?from=${reportDate}&to=${reportDate}`, pathPrefix), {
        method: 'GET',
        credentials: 'same-origin',
        headers: {
          Accept: 'application/json',
        },
      });

      if (premiumReports.status !== 200 || !premiumReports.body || premiumReports.body.status !== 'ok') {
        throw new Error(`Owner dashboard reports should be available after playback qualification. Received: ${JSON.stringify(premiumReports)}`);
      }

      if (Number(premiumReports.body.totals && premiumReports.body.totals.views) !== 1) {
        throw new Error(`Only one payable anonymous view should be counted for the day. Received: ${JSON.stringify(premiumReports.body.totals || null)}`);
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
