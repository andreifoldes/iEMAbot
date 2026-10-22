// Release builds are strict. `scripts/build.sh debug` sets ESMIRA_DEBUG=1 so a debug APK can
// talk to a plain-http test server (e.g. the emulator's host alias).
const debug = process.env.ESMIRA_DEBUG === '1';

module.exports = {
  appId: 'org.esmira.participant',
  appName: 'ESMira',
  webDir: '../dist/pwa-native',
  android: {
    allowMixedContent: debug,
    adjustMarginsForEdgeToEdge: 'force',
  },
  server: {
    // Fixed so the WebView origin (and with it localStorage/IndexedDB) never changes between releases.
    androidScheme: 'https',
  },
  plugins: {
    // Status-bar icon + accent for reminder notifications (res/drawable/ic_stat_esmira.xml).
    LocalNotifications: { smallIcon: 'ic_stat_esmira', iconColor: '#065f52' },
  },
};
