// Release builds are strict. `scripts/build.sh debug` sets ESMIRA_DEBUG=1 so a debug APK can
// talk to a plain-http test server (e.g. the emulator's host alias).
const debug = process.env.ESMIRA_DEBUG === '1';

module.exports = {
  appId: 'org.iemabot.app',
  appName: 'iEMAbot',
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
    // Only used for the notification permission now: reminders are posted by the app's own Reminders plugin
    // (ReminderNotifier.java, same look and stacking as the PWA). Kept in step in case it ever posts one.
    LocalNotifications: { smallIcon: 'ic_stat_esmira', iconColor: '#075E54' },
  },
};
