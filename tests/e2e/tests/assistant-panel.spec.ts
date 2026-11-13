import { test, expect } from '@playwright/test';
import fs from 'node:fs';
import path from 'node:path';
import { execFileSync } from 'node:child_process';

test.use({ screenshot: 'off', trace: 'off', video: 'off' });
const root = path.resolve(__dirname, '../../..');
const source = (file: string) => process.env.ASSISTANT_BASE_REF
  ? execFileSync('git', ['show', `${process.env.ASSISTANT_BASE_REF}:${file}`], { cwd: root, encoding: 'utf8', maxBuffer: 16 * 1024 * 1024 })
  : fs.readFileSync(path.join(root, file), 'utf8');
const ui = source('class/class-mainwp-ui.php');
const stripPhp = (html: string) => html.replace(/<\?php[\s\S]*?\?>/g, part =>
  (part.match(/(?:esc_attr_e|esc_html_e)\( '((?:\\.|[^'\\])*)'/)?.[1] || '').replace(/\\(['\\])/g, '$1'));

async function fixture(page: any) {
  const close = stripPhp(ui.match(/<button class="close-button"[\s\S]*?<\/button>/)![0]);
  const pills = stripPhp(ui.match(/<div class="mainwp-assistant-mode-selector">[\s\S]*?<span class="mode-selector-spacer"><\/span>\s*<\/div>/)![0]);
  const info = stripPhp(ui.match(/<div class="mainwp-assistant-info-bar"[\s\S]*?<\/div>/)![0]);
  await page.setContent(`<body class="mainwp-ui"><button id="open">Open</button><div id="mainwp-loading-sites" style="display:none"><div class="ui active dimmer"></div></div><div id="mainwp-assistant-panel" style="display:flex"><div class="mainwp-assistant-panel-header">${close}</div><div style="padding:16px">${pills}${info}</div></div></body>`);
  for (const file of ['assets/js/fomantic-ui/fomantic-ui.css', 'assets/css/mainwp-fomantic.css', 'assets/css/mainwp-assistant-panel.css']) {
    await page.addStyleTag({ content: source(file) });
  }
  const wpRoot = path.resolve(process.env.WP_TESTS_DIR || path.join(process.env.HOME!, 'wordpress-tests-lib'), '../wordpress');
  await page.addScriptTag({ path: path.join(wpRoot, 'wp-includes/js/jquery/jquery.js') });
  const chat = source('assets/js/mainwp-mintlify-chat.js');
  const infoStart = chat.indexOf('    function updateInfoBar( mode ) {');
  expect(infoStart, 'info bar handler anchor').toBeGreaterThan(-1);
  const updateInfoBar = chat.slice(infoStart, chat.indexOf('\n    }', infoStart) + 6);
  await page.addScriptTag({ content: `var $=jQuery; ${updateInfoBar}; jQuery('.mainwp-mode-pill').on('click', function(){updateInfoBar(this.dataset.mode);});` });
  const panel = source('assets/js/mainwp-assistant-panel.js');
  const start = panel.indexOf("        $( document ).on( 'keydown.assistantPanel'");
  expect(start, 'keydown handler anchor').toBeGreaterThan(-1);
  const keyboard = panel.slice(start, panel.indexOf('\n        } );', start) + 13);
  const main = source('assets/js/mainwp.js');
  const globalStart = main.indexOf("jQuery(document).on('click', '.close.icon'");
  expect(globalStart, 'global close handler anchor').toBeGreaterThan(-1);
  const globalClose = main.slice(globalStart, main.indexOf('\n});', globalStart) + 4);
  await page.addScriptTag({ content: `var state={isOpen:true,panelState:'normal'}; var MainWPAssistantPanel={close:function(){state.isOpen=false;jQuery('#mainwp-assistant-panel').hide();}}; var $=jQuery; jQuery('.close-button').on('click',function(){MainWPAssistantPanel.close();}); jQuery('#open').on('click',function(){state.isOpen=true;jQuery('#mainwp-assistant-panel').show();}); ${globalClose}\n${keyboard}` });
}

test('Manage Sites hidden loader permits Escape and the panel X survives reopen', async ({ page }) => {
  await fixture(page);
  await page.locator('.close-button i').click();
  await page.locator('#open').click();
  await expect(page.locator('.close-button')).toBeVisible();
  await page.keyboard.press('Escape');
  await expect(page.locator('#mainwp-assistant-panel')).toBeHidden();
});

test('mode pills have no tooltips and the Mode label tooltip fits the 400 px panel', async ({ page }) => {
  await fixture(page);
  await expect(page.locator('#mainwp-assistant-panel')).toHaveCSS('width', '400px');
  for (const mode of ['ask', 'agent']) {
    const pill = page.locator(`[data-mode="${mode}"]`);
    for (const attribute of ['data-tooltip', 'data-inverted', 'data-position', 'title']) {
      expect(await pill.getAttribute(attribute), `${mode} ${attribute}`).toBeNull();
    }
  }
  // The tooltip box is measured from its computed ::after style, which exists without hover.
  const label = page.locator('.mode-label');
  const geometry = await label.evaluate((element: HTMLElement) => {
    const style = getComputedStyle(element, '::after');
    const marker = document.createElement('span');
    marker.textContent = element.dataset.tooltip!;
    marker.style.cssText = style.cssText;
    for (const key of Array.from(style)) marker.style.setProperty(key, style.getPropertyValue(key));
    marker.style.content = 'normal';
    element.appendChild(marker);
    const rect = marker.getBoundingClientRect();
    const panel = document.querySelector('#mainwp-assistant-panel')!.getBoundingClientRect();
    marker.remove();
    return { display: style.display, maxWidth: style.maxWidth, left: rect.left, right: rect.right, panelLeft: panel.left, panelRight: panel.right };
  });
  expect(geometry.display).not.toBe('none');
  expect(geometry.maxWidth).toBe('230px');
  expect(geometry.left).toBeGreaterThanOrEqual(geometry.panelLeft);
  expect(geometry.right).toBeLessThanOrEqual(geometry.panelRight);
});

for (const width of [400, 600]) {
  test(`info bar keeps its height when switching modes at ${width} px`, async ({ page }) => {
    await fixture(page);
    await page.locator('#mainwp-assistant-panel').evaluate((element, panelWidth) => {
      element.style.width = `${panelWidth}px`;
    }, width);
    const ask = page.locator('.info-bar-ask');
    const agent = page.locator('.info-bar-agent');
    await expect(ask).toHaveText("Answers from MainWP documentation. Your question, this dashboard's address, the page you're on and your Assistant access settings are sent to MainWP's documentation service.");
    const heights: number[] = [];
    for (const mode of ['ask', 'agent', 'ask']) {
      await page.locator(`[data-mode="${mode}"]`).click();
      await expect(mode === 'ask' ? ask : agent).toBeVisible();
      await expect(mode === 'ask' ? agent : ask).toBeHidden();
      heights.push((await page.locator('#mainwp-assistant-info-bar').boundingBox())!.height);
    }
    expect(heights[1]).toBe(heights[0]);
    expect(heights[2]).toBe(heights[0]);
  });
}
