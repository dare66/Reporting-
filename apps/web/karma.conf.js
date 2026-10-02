// Karma config: adds a sandbox-free headless Chrome for containers and CI runners.
module.exports = function (config) {
  config.set({
    basePath: '',
    frameworks: ['jasmine'],
    plugins: [require('karma-jasmine'), require('karma-chrome-launcher'), require('karma-jasmine-html-reporter'), require('karma-coverage')],
    customLaunchers: { ChromeHeadlessCI: { base: 'ChromeHeadless', flags: ['--no-sandbox', '--disable-gpu'] } },
    browsers: ['ChromeHeadlessCI'],
    reporters: ['progress'],
    singleRun: true,
    restartOnFileChange: false,
  });
};
