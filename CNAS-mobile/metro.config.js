// The navigation engine (route graph, router, directions, GPS smoothing, tracker, voice prompts) is not copied into the
// app: it is imported from the website, CNAS-web/resources/js/navigation, so the phone and /navigate give the same
// routes. Those files only import each other and single Turf modules (checked by CNAS-web
// tests/js/navigation-shared.test.js); Turf resolves from this app's node_modules. EAS uploads the whole git
// repository, so the folder is there in cloud builds too. Imported as @nav/<file>.js (tsconfig.json paths).
const path = require('path');
const { getDefaultConfig } = require('expo/metro-config');

const config = getDefaultConfig(__dirname);
const navigation = path.resolve(__dirname, '../CNAS-web/resources/js/navigation');

config.watchFolders = [...(config.watchFolders ?? []), navigation];
config.resolver.nodeModulesPaths = [...(config.resolver.nodeModulesPaths ?? []), path.resolve(__dirname, 'node_modules')];

module.exports = config;
