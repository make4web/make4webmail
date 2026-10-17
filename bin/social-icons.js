// Draws the social network icons of signatures (public/assets/social/*.png) from the Bootstrap
// Icons font: PNG, since mail programs do not show SVG. Development tool, needs Playwright:
//   node bin/social-icons.js
const path = require('path');
const { chromium } = require('playwright');
const NETS = {
  linkedin: '#0a66c2', facebook: '#1877f2', instagram: '#e4405f', 'twitter-x': '#000000', youtube: '#ff0000', tiktok: '#000000',
  github: '#181717', whatsapp: '#25d366', pinterest: '#bd081c', threads: '#000000', mastodon: '#6364ff', bluesky: '#0085ff',
  vimeo: '#1ab7ea', discord: '#5865f2', telegram: '#26a5e4', globe2: '#475569',
};
(async () => {
  const root = path.resolve(__dirname, '..');
  const b = await chromium.launch(process.env.CHROMIUM ? { executablePath: process.env.CHROMIUM } : {});
  const p = await b.newPage({ deviceScaleFactor: 1 });
  const css = 'file://' + root + '/public/assets/vendor/bootstrap-icons/bootstrap-icons.min.css';
  const cells = Object.entries(NETS).map(([n, c]) =>
    `<span id="c-${n}" class="t" style="background:${c}"><i class="bi bi-${n}"></i></span><span id="g-${n}" class="t" style="background:#64748b"><i class="bi bi-${n}"></i></span>`).join('');
  const html = require('os').tmpdir() + '/m4w-social-icons.html';
  require('fs').writeFileSync(html, `<link rel="stylesheet" href="${css}"><style>body{margin:0;background:transparent}
    .t{display:inline-flex;align-items:center;justify-content:center;width:48px;height:48px;border-radius:10px;margin:4px;color:#fff;font-size:28px}</style>${cells}`);
  await p.goto('file://' + html);
  await p.evaluate(() => document.fonts.load('28px bootstrap-icons'));
  await p.evaluate(() => document.fonts.ready);
  for (const n of Object.keys(NETS)) {
    const name = n === 'twitter-x' ? 'x' : (n === 'globe2' ? 'website' : n);
    await (await p.$('#c-' + n)).screenshot({ path: `${root}/public/assets/social/${name}.png`, omitBackground: true });
    await (await p.$('#g-' + n)).screenshot({ path: `${root}/public/assets/social/${name}-gray.png`, omitBackground: true });
  }
  await b.close();
})();
