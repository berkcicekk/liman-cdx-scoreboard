const {defineConfig} = require('@playwright/test');
module.exports = defineConfig({
    testDir: __dirname, testMatch: 'ui.spec.js', workers: 1,
    outputDir: '.runtime/playwright', reporter: 'list',
    use: {baseURL: 'http://127.0.0.1:18765', headless: true, viewport: {width: 1440,height:1000}}
});
