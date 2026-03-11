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

function toolbarButton(page, label) {
  return page.locator('.title_wrap .btn-group .btn').filter({ hasText: label }).first();
}

function basePathFromUrl(value) {
  const pathname = new URL(value).pathname.replace(/\/+$/, '');
  return pathname === '/' ? '' : pathname;
}

function appPath(basePath, path) {
  const suffix = path.startsWith('/') ? path : `/${path}`;
  return `${basePath}${suffix}` || '/';
}

async function login(page, username, password, basePath) {
  await page.goto(appPath(basePath, '/'), { waitUntil: 'networkidle' });
  const token = await page.evaluate(() => {
    if (window.VE_CSRF_TOKEN) {
      return window.VE_CSRF_TOKEN;
    }

    const field = document.querySelector('input[name="token"]');
    return field ? field.value : '';
  });

  if (!token) {
    throw new Error('Unable to extract the runtime CSRF token.');
  }

  const result = await page.evaluate(async (credentials) => {
    const response = await fetch(credentials.loginPath, {
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

    return {
      status: response.status,
      payload: await response.json(),
    };
  }, {
    username,
    password,
    token,
    loginPath: appPath(basePath, '/api/auth/login'),
  });

  if (result.status !== 200 || result.payload.status !== 'redirect') {
    throw new Error(`Login failed: ${JSON.stringify(result)}`);
  }
}

async function dismissModalIfVisible(page, selector) {
  const modal = page.locator(selector);

  if (!(await modal.count())) {
    return;
  }

  const isVisible = await modal.evaluate((node) => {
    if (!node) {
      return false;
    }

    const classes = node.className || '';
    const style = window.getComputedStyle(node);
    return classes.indexOf('show') !== -1 || style.display !== 'none';
  });

  if (!isVisible) {
    return;
  }

  await page.evaluate((modalSelector) => {
    if (window.jQuery) {
      window.jQuery(modalSelector).modal('hide');
    }

    const node = document.querySelector(modalSelector);

    if (node) {
      node.classList.remove('show');
      node.setAttribute('aria-hidden', 'true');
      node.style.display = 'none';
    }

    document.body.classList.remove('modal-open');
    document.body.style.removeProperty('padding-right');
    document.querySelectorAll('.modal-backdrop').forEach((backdrop) => backdrop.remove());
  }, selector);

  await page.waitForFunction((modalSelector) => {
    const node = document.querySelector(modalSelector);

    if (!node) {
      return true;
    }

    const style = window.getComputedStyle(node);
    return !node.classList.contains('show') && style.display === 'none';
  }, selector);

  await page.waitForFunction(() => !document.querySelector('.modal-backdrop.show'));
}

function findVideoManagerInstance(node) {
  const queue = node && node.$children ? node.$children.slice() : [];

  while (queue.length > 0) {
    const child = queue.shift();

    if (child && child.$options && (child.$options._componentTag === 'video-manager' || child.$options.name === 'video-manager')) {
      return child;
    }

    if (child && child.$children) {
      queue.push(...child.$children);
    }
  }

  return null;
}

(async () => {
  const baseURL = requiredEnv('VIDEOS_BROWSER_BASE_URL');
  const username = requiredEnv('VIDEOS_BROWSER_USER');
  const password = requiredEnv('VIDEOS_BROWSER_PASSWORD');
  const sharedFolderName = requiredEnv('VIDEOS_BROWSER_SHARED_FOLDER');
  const targetFolderName = requiredEnv('VIDEOS_BROWSER_TARGET_FOLDER');
  const nestedDropFolderName = 'Nested Drop Folder';
  const basePath = basePathFromUrl(baseURL);
  const browserPath = firstExistingPath([
    process.env.VIDEOS_BROWSER_EXECUTABLE || '',
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
    const seenRequests = [];
    const context = await browser.newContext({
      baseURL,
      serviceWorkers: 'block',
      extraHTTPHeaders: {
        'Cache-Control': 'no-cache',
        Pragma: 'no-cache',
      },
    });
    const page = await context.newPage();
    page.on('request', (request) => {
      if (request.url().startsWith(baseURL)) {
        seenRequests.push(`${request.method()} ${request.url()}`);
      }
    });

    await login(page, username, password, basePath);

    await page.goto(appPath(basePath, '/dashboard/videos'), { waitUntil: 'networkidle' });

    if (!page.url().endsWith(appPath(basePath, '/videos'))) {
      throw new Error(`Expected /dashboard/videos to redirect to ${appPath(basePath, '/videos')}. Received ${page.url()}`);
    }

    await page.waitForFunction(() => {
      const modal = document.querySelector('#content_type');
      if (!modal) {
        return false;
      }

      const style = window.getComputedStyle(modal);
      return modal.classList.contains('show') || style.display !== 'none';
    });

    await page.check('#adult', { force: true });
    await page.locator('#content_type .modal-footer .btn.btn-primary').click();
    await page.waitForFunction(() => {
      const modal = document.querySelector('#content_type');
      if (!modal) {
        return true;
      }

      const style = window.getComputedStyle(modal);
      return !modal.classList.contains('show') && style.display === 'none';
    });

    await page.waitForFunction(() => {
      const root = document.querySelector('.file_manager.d-flex.flex-wrap');
      const shareButton = Array.from(document.querySelectorAll('.title_wrap .btn-group .btn')).find((button) =>
        (button.textContent || '').includes('Share')
      );
      const folderRow = document.querySelector('.file_list .folder.item .size');
      const pathCard = document.querySelector('[data-folder-path-card][data-folder-id="0"]');
      const perPage = document.querySelector('[data-results-per-page]');
      return Boolean(root && shareButton && folderRow && pathCard && perPage);
    });

    const contentTypeAfterSave = await page.evaluate(() => {
      const app = document.getElementById('app');
      return app ? app.getAttribute('data-uploader-type') : '';
    });

    if (contentTypeAfterSave !== '2') {
      throw new Error(`Content type was not persisted into the dashboard shell. Received: ${contentTypeAfterSave || '(empty)'}`);
    }

    await page.reload({ waitUntil: 'networkidle' });
    const contentTypeModal = page.locator('#content_type');
    const contentModalVisibleAfterReload = (await contentTypeModal.count())
      ? await contentTypeModal.evaluate((node) => {
        if (!node) {
          return false;
        }

        const style = window.getComputedStyle(node);
        return node.classList.contains('show') || style.display !== 'none';
      })
      : false;

    if (contentModalVisibleAfterReload) {
      throw new Error('Content type modal should not reopen after the saved account preference is set.');
    }

    const toolbarLabels = await page.locator('.title_wrap .btn-group .btn').allTextContents();

    if (!toolbarLabels.some((label) => label.includes('Share'))) {
      throw new Error(`Share button was not rendered in the toolbar: ${JSON.stringify(toolbarLabels)}`);
    }

    if (toolbarLabels.some((label) => label.includes('Export'))) {
      throw new Error(`Legacy Export button should no longer appear: ${JSON.stringify(toolbarLabels)}`);
    }

    const rootBreadcrumbLabel = (await page.locator('[data-folder-path-card][data-folder-id="0"]').textContent() || '').trim();

    if (rootBreadcrumbLabel !== '/Videos') {
      throw new Error(`Root breadcrumb should start with /Videos. Received: ${rootBreadcrumbLabel || '(empty)'}`);
    }

    await page.selectOption('[data-results-per-page]', '50');
    await page.waitForFunction(() => {
      const select = document.querySelector('[data-results-per-page]');
      if (!select) {
        return false;
      }

      const app = document.getElementById('app');
      const root = app && app.__vue__;

      function findVideoManagerInstance(node) {
        const queue = node && node.$children ? node.$children.slice() : [];
        while (queue.length > 0) {
          const child = queue.shift();
          if (child && child.$options && (child.$options._componentTag === 'video-manager' || child.$options.name === 'video-manager')) {
            return child;
          }
          if (child && child.$children) {
            queue.push(...child.$children);
          }
        }
        return null;
      }

      const vm = findVideoManagerInstance(root);
      return select.value === '50' && vm && String(vm.per_page || '') === '50';
    });

    if (!seenRequests.some((entry) => entry.includes('/videos/actions') && entry.includes('per_page=50'))) {
      throw new Error(`Changing the results-per-page control did not call /videos/actions with per_page=50. Seen requests: ${JSON.stringify(seenRequests)}`);
    }

    const sharedFolderRow = page.locator('.file_list .folder.item').filter({ hasText: sharedFolderName });
    const sharedFolderSize = (await sharedFolderRow.locator('.size').textContent() || '').trim();
    const sharedFolderCreated = (await sharedFolderRow.locator('.date').textContent() || '').trim();
    const sharedFolderViews = (await sharedFolderRow.locator('.views').textContent() || '').trim();
    const sharedFolderColumnOrder = await sharedFolderRow.evaluate((node) => {
      const children = Array.from(node.children);
      const indexFor = (className) => children.findIndex((child) => child.classList.contains(className));

      return {
        name: indexFor('name'),
        size: indexFor('size'),
        date: indexFor('date'),
        views: indexFor('views'),
      };
    });

    if (!sharedFolderSize || sharedFolderSize === '-') {
      throw new Error(`Folder size was not rendered in the table. Received: ${sharedFolderSize || '(empty)'}`);
    }

    if (!sharedFolderCreated || sharedFolderCreated === '-') {
      throw new Error(`Folder created date was not rendered in the table. Received: ${sharedFolderCreated || '(empty)'}`);
    }

    if (sharedFolderViews !== '-') {
      throw new Error(`Folder views should always render as "-". Received: ${sharedFolderViews || '(empty)'}`);
    }

    if (!(sharedFolderColumnOrder.name > -1 && sharedFolderColumnOrder.size > sharedFolderColumnOrder.name && sharedFolderColumnOrder.date > sharedFolderColumnOrder.size && sharedFolderColumnOrder.views > sharedFolderColumnOrder.date)) {
      throw new Error(`Folder row columns were not ordered correctly: ${JSON.stringify(sharedFolderColumnOrder)}`);
    }

    await sharedFolderRow.locator('a.name').click();
    await page.waitForFunction((name) => {
      const videoTitle = document.querySelector('.file_list .video.item h4 a');
      return Boolean(videoTitle && videoTitle.textContent && videoTitle.textContent.includes(name));
    }, 'Shared Folder Clip');

    const breadcrumbLabelsInsideFolder = await page.locator('[data-folder-path-card]').allTextContents();

    if (!(breadcrumbLabelsInsideFolder.includes('/Videos') && breadcrumbLabelsInsideFolder.some((label) => label.trim() === sharedFolderName))) {
      throw new Error(`Breadcrumb path did not include the current folder. Received: ${JSON.stringify(breadcrumbLabelsInsideFolder)}`);
    }

    await toolbarButton(page, 'Share').click();
    await page.waitForFunction(() => {
      const modal = document.querySelector('#sharing.show, #sharing[style*="display: block"]');
      const textarea = document.querySelector('#sharing textarea');
      return Boolean(modal && textarea && textarea.value.includes('/videos/shared/'));
    });

    const currentFolderShareLink = (await page.locator('#sharing textarea').inputValue()).trim();

    if (!currentFolderShareLink.includes('/videos/shared/')) {
      throw new Error(`Current folder share link was not rendered correctly. Received: ${currentFolderShareLink || '(empty)'}`);
    }

    await page.evaluate(() => {
      const toggle = document.querySelector('#sharing [data-share-folder-toggle]');

      if (!toggle) {
        throw new Error('Folder share toggle was not rendered.');
      }

      toggle.checked = true;
      toggle.dispatchEvent(new Event('change', { bubbles: true }));
    });
    await page.waitForFunction((folderName) => {
      const textarea = document.querySelector('#sharing [data-share-folder-output]');
      return Boolean(
        textarea
        && textarea.value.includes(folderName)
        && textarea.value.includes('/videos/shared/')
        && textarea.value.indexOf(folderName) < textarea.value.indexOf('/videos/shared/')
      );
    }, sharedFolderName);

    const titledFolderShareLink = (await page.locator('#sharing [data-share-folder-output]').inputValue()).trim();

    if (!(titledFolderShareLink.includes(sharedFolderName) && titledFolderShareLink.indexOf(sharedFolderName) < titledFolderShareLink.indexOf('/videos/shared/'))) {
      throw new Error(`Folder share modal did not prepend the folder title. Received: ${titledFolderShareLink || '(empty)'}`);
    }

    await dismissModalIfVisible(page, '#sharing');
    await page.locator('[data-action="go-back"]').click();
    await page.waitForFunction((name) => {
      return Array.from(document.querySelectorAll('.file_list .folder.item .title')).some((node) => (node.textContent || '').includes(name));
    }, targetFolderName);

    await page.evaluate(() => {
      const app = document.getElementById('app');
      const root = app && app.__vue__;

      function findVideoManagerInstance(node) {
        const queue = node && node.$children ? node.$children.slice() : [];
        while (queue.length > 0) {
          const child = queue.shift();
          if (child && child.$options && (child.$options._componentTag === 'video-manager' || child.$options.name === 'video-manager')) {
            return child;
          }
          if (child && child.$children) {
            queue.push(...child.$children);
          }
        }
        return null;
      }

      const vm = findVideoManagerInstance(root);
      vm.current_folder = 0;
      vm.page = 1;
      vm.update();
    });

    await page.waitForFunction((name) => {
      return Array.from(document.querySelectorAll('.file_list .folder.item .title')).some((node) => (node.textContent || '').includes(name));
    }, targetFolderName);
    const targetFolderRow = page.locator('.file_list .folder.item').filter({ hasText: targetFolderName });
    const selectedFolderId = await targetFolderRow.getAttribute('data-folder');

    if (!selectedFolderId) {
      throw new Error(`Move target folder row was not rendered: ${targetFolderName}`);
    }

    await page.evaluate(async (folderId) => {
      function findVideoManagerInstance(node) {
        const queue = node && node.$children ? node.$children.slice() : [];

        while (queue.length > 0) {
          const child = queue.shift();

          if (child && child.$options && (child.$options._componentTag === 'video-manager' || child.$options.name === 'video-manager')) {
            return child;
          }

          if (child && child.$children) {
            queue.push(...child.$children);
          }
        }

        return null;
      }

      const rows = Array.from(document.querySelectorAll('.file_list .video.item')).slice(0, 2);
      const app = document.getElementById('app');
      const root = app && app.__vue__;
      const vm = findVideoManagerInstance(root);

      if (!vm) {
        throw new Error('Unable to locate the video manager instance.');
      }

      const fileIds = rows.map((row) => String(row.getAttribute('data-video') || '')).filter(Boolean);
      const payload = new URLSearchParams();
      payload.append('file_move', '1');
      payload.append('to_folder', String(folderId || ''));
      payload.append('token', String(vm.token || ''));
      fileIds.forEach((id) => payload.append('file_id[]', id));
      const basePath = window.VE_BASE_PATH || '';
      const actionsUrl = `${basePath}/videos/actions`;

      const response = await fetch(actionsUrl, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
          Accept: 'application/json',
          'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
        },
        body: payload.toString(),
      });

      const result = await response.json();

      if (response.status !== 200 || result.status !== 'ok') {
        throw new Error(`Unable to seed the multi-file move state: ${JSON.stringify({ status: response.status, result })}`);
      }

      vm.update();
    }, selectedFolderId);
    await page.waitForTimeout(1000);
    const postMoveState = await page.evaluate(() => {
      function findVideoManagerInstance(node) {
        const queue = node && node.$children ? node.$children.slice() : [];

        while (queue.length > 0) {
          const child = queue.shift();

          if (child && child.$options && (child.$options._componentTag === 'video-manager' || child.$options.name === 'video-manager')) {
            return child;
          }

          if (child && child.$children) {
            queue.push(...child.$children);
          }
        }

        return null;
      }

      const app = document.getElementById('app');
      const root = app && app.__vue__;
      const vm = findVideoManagerInstance(root);

      return {
        selectedFolder: vm && vm.$root ? String(vm.$root.selected_folder || '') : '',
        currentFolder: vm ? String(vm.current_folder || '') : '',
        fileIds: vm && Array.isArray(vm.file_ids) ? vm.file_ids.slice() : [],
        folderIds: vm && Array.isArray(vm.folder_ids) ? vm.folder_ids.slice() : [],
        visibleTitles: Array.from(document.querySelectorAll('.file_list .video.item h4 a')).map((node) => (node.textContent || '').trim()),
      };
    });

    if (postMoveState.visibleTitles.length !== 0) {
      throw new Error(`Multi-file move did not update the root listing. State=${JSON.stringify(postMoveState)} Requests=${JSON.stringify(seenRequests)}`);
    }

    const targetFolderSize = (await targetFolderRow.locator('.size').textContent() || '').trim();

    if (!targetFolderSize || targetFolderSize === '0 B') {
      throw new Error(`Moved folder size did not update after multi-file move. Received: ${targetFolderSize || '(empty)'}`);
    }

    await page.evaluate((folderId) => {
      function findVideoManagerInstance(node) {
        const queue = node && node.$children ? node.$children.slice() : [];

        while (queue.length > 0) {
          const child = queue.shift();

          if (child && child.$options && (child.$options._componentTag === 'video-manager' || child.$options.name === 'video-manager')) {
            return child;
          }

          if (child && child.$children) {
            queue.push(...child.$children);
          }
        }

        return null;
      }

      const app = document.getElementById('app');
      const root = app && app.__vue__;
      const vm = findVideoManagerInstance(root);

      if (vm) {
        vm.current_folder = Number(folderId) || 0;
        vm.page = 1;
        vm.update();
      }
    }, selectedFolderId);
    await page.waitForFunction(() => document.querySelectorAll('.file_list .video.item').length === 2);
    await page.waitForFunction((name) => {
      return Array.from(document.querySelectorAll('.file_list .folder.item .title')).some((node) => (node.textContent || '').includes(name));
    }, nestedDropFolderName);

    const movedTitles = await page.locator('.file_list .video.item h4 a').allTextContents();

    if (movedTitles.length !== 2) {
      throw new Error(`Expected both selected files to be moved. Received: ${JSON.stringify(movedTitles)}`);
    }

    await page.evaluate((folderName) => {
      const rows = Array.from(document.querySelectorAll('.file_list .video.item')).slice(0, 2);

      rows.forEach((row) => {
        row.classList.add('active');
        const input = row.querySelector('input.custom-control-input');

        if (input) {
          input.checked = true;
          input.dispatchEvent(new Event('change', { bubbles: true }));
        }
      });

      const source = rows[0] || document.querySelector('.file_list .video.item');
      const target = Array.from(document.querySelectorAll('.file_list .folder.item')).find((node) =>
        (node.textContent || '').includes(folderName)
      );

      if (!source || !target) {
        throw new Error('Unable to find the drag/drop source or target rows.');
      }

      const dataTransfer = new DataTransfer();
      source.dispatchEvent(new DragEvent('dragstart', { bubbles: true, cancelable: true, dataTransfer }));
      target.dispatchEvent(new DragEvent('dragenter', { bubbles: true, cancelable: true, dataTransfer }));
      target.dispatchEvent(new DragEvent('dragover', { bubbles: true, cancelable: true, dataTransfer }));
      target.dispatchEvent(new DragEvent('drop', { bubbles: true, cancelable: true, dataTransfer }));
      source.dispatchEvent(new DragEvent('dragend', { bubbles: true, cancelable: true, dataTransfer }));
    }, nestedDropFolderName);

    await page.waitForFunction(() => document.querySelectorAll('.file_list .video.item').length === 0);
    await page.locator('.file_list .folder.item').filter({ hasText: nestedDropFolderName }).locator('a.name').click();
    await page.waitForFunction(() => document.querySelectorAll('.file_list .video.item').length === 2);

    const nestedMovedTitles = await page.locator('.file_list .video.item h4 a').allTextContents();

    if (nestedMovedTitles.length !== 2) {
      throw new Error(`Drag/drop move did not place both videos into the nested folder. Received: ${JSON.stringify(nestedMovedTitles)}`);
    }

    await page.evaluate(() => {
      function findVideoManagerInstance(node) {
        const queue = node && node.$children ? node.$children.slice() : [];

        while (queue.length > 0) {
          const child = queue.shift();

          if (child && child.$options && (child.$options._componentTag === 'video-manager' || child.$options.name === 'video-manager')) {
            return child;
          }

          if (child && child.$children) {
            queue.push(...child.$children);
          }
        }

        return null;
      }

      const app = document.getElementById('app');
      const root = app && app.__vue__;
      const vm = findVideoManagerInstance(root);

      if (vm) {
        vm.current_folder = 0;
        vm.page = 1;
        vm.update();
      }
    });
    await page.waitForFunction((name) => {
      return Array.from(document.querySelectorAll('.file_list .folder.item .title')).some((node) => (node.textContent || '').includes(name));
    }, sharedFolderName);

    const sharedFolderId = await sharedFolderRow.getAttribute('data-folder');

    await page.evaluate((folderName) => {
      const target = Array.from(document.querySelectorAll('.file_list .folder.item')).find((node) =>
        (node.textContent || '').includes(folderName)
      );

      if (!target) {
        throw new Error(`Unable to find folder row for ${folderName}.`);
      }

      const dataTransfer = new DataTransfer();
      dataTransfer.items.add(new File(['drop-a'], 'drop-a.mp4', { type: 'video/mp4' }));
      dataTransfer.items.add(new File(['drop-b'], 'drop-b.mov', { type: 'video/quicktime' }));
      target.dispatchEvent(new DragEvent('dragenter', { bubbles: true, cancelable: true, dataTransfer }));
      target.dispatchEvent(new DragEvent('dragover', { bubbles: true, cancelable: true, dataTransfer }));
      target.dispatchEvent(new DragEvent('drop', { bubbles: true, cancelable: true, dataTransfer }));
    }, sharedFolderName);

    await page.waitForTimeout(1500);
    const folderDropState = await page.evaluate((folderId) => {
      function findVideoManagerInstance(node) {
        const queue = node && node.$children ? node.$children.slice() : [];

        while (queue.length > 0) {
          const child = queue.shift();

          if (child && child.$options && (child.$options._componentTag === 'video-manager' || child.$options.name === 'video-manager')) {
            return child;
          }

          if (child && child.$children) {
            queue.push(...child.$children);
          }
        }

        return null;
      }

      const app = document.getElementById('app');
      const root = app && app.__vue__;
      const vm = findVideoManagerInstance(root);
      const upload = vm && vm.$refs ? vm.$refs.upload : null;
      const uploadFiles = upload && Array.isArray(upload.files) ? upload.files : [];
      const dropA = uploadFiles.find((file) => (file && (file.name || (file.file && file.file.name) || '')) === 'drop-a.mp4');
      const dropB = uploadFiles.find((file) => (file && (file.name || (file.file && file.file.name) || '')) === 'drop-b.mov');

      return {
        matches: Boolean(
          dropA
          && dropB
          && String((((dropA.data || {}).fld_id) || '')) === String(folderId || '')
          && String((((dropB.data || {}).fld_id) || '')) === String(folderId || '')
        ),
        files: uploadFiles.map((file) => ({
          name: file && (file.name || (file.file && file.file.name) || ''),
          folderId: String((((file && file.data) || {}).fld_id) || ''),
        })),
      };
    }, sharedFolderId || '');

    if (!folderDropState.matches) {
      throw new Error(`Folder-target upload drop did not assign both files to the target folder. State=${JSON.stringify(folderDropState)}`);
    }

    await page.evaluate(() => {
      const root = document.querySelector('.file_manager.d-flex.flex-wrap');
      const dataTransfer = new DataTransfer();
      dataTransfer.items.add(new File(['drop-test'], 'dropped.mp4', { type: 'video/mp4' }));
      root.dispatchEvent(new DragEvent('dragenter', { bubbles: true, cancelable: true, dataTransfer }));
      root.dispatchEvent(new DragEvent('dragover', { bubbles: true, cancelable: true, dataTransfer }));
      root.dispatchEvent(new DragEvent('drop', { bubbles: true, cancelable: true, dataTransfer }));
    });

    await page.waitForFunction(() => {
      function findVideoManagerInstance(node) {
        const queue = node && node.$children ? node.$children.slice() : [];

        while (queue.length > 0) {
          const child = queue.shift();

          if (child && child.$options && (child.$options._componentTag === 'video-manager' || child.$options.name === 'video-manager')) {
            return child;
          }

          if (child && child.$children) {
            queue.push(...child.$children);
          }
        }

        return null;
      }

      const app = document.getElementById('app');
      const root = app && app.__vue__;
      const vm = findVideoManagerInstance(root);
      const upload = vm && vm.$refs ? vm.$refs.upload : null;
      const uploadFiles = upload && Array.isArray(upload.files) ? upload.files : [];

      return uploadFiles.some((file) => {
        const name = file && (file.name || (file.file && file.file.name) || '');
        return name === 'dropped.mp4';
      });
    });

    const forbiddenPaths = [
      '/api/videos/actions',
      '/api/folders/share',
      '/api/videos/upload-target',
      '/api/uploads/result',
      '/api/uploads/check',
    ];

    forbiddenPaths.forEach((path) => {
      if (seenRequests.some((requestLine) => requestLine.includes(path))) {
        throw new Error(`Videos dashboard should not call legacy API path ${path}: ${JSON.stringify(seenRequests)}`);
      }
    });

    const requiredPaths = ['/videos/actions', '/videos/share'];
    requiredPaths.forEach((path) => {
      if (!seenRequests.some((requestLine) => requestLine.includes(path))) {
        throw new Error(`Expected videos dashboard to call ${path}: ${JSON.stringify(seenRequests)}`);
      }
    });
  } finally {
    await browser.close();
  }

  process.stdout.write('videos browser ok\n');
})().catch((error) => {
  process.stderr.write(String(error && error.stack ? error.stack : error) + '\n');
  process.exit(1);
});
