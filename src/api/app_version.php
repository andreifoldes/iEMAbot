<?php

use backend\JsonOutput;

require_once dirname(__FILE__, 2) .'/backend/autoload.php';

if(\backend\Cors::handleNativeAppRequest()) return;
// The Android app built from the same commit as this server (deploy.yml writes app/android.json and app/iemabot.apk
// next to the server code): {versionCode, versionName, sha, apk}, or null when this server has no app build. The
// app compares it with its own build and offers the update (web-pwa/src/lib/appUpdate.ts).

$raw = @file_get_contents(DIR_BASE . 'app/android.json');
$release = is_string($raw) ? json_decode($raw, true) : null;
echo JsonOutput::successObj(is_array($release) && is_file(DIR_BASE . 'app/iemabot.apk') ? $release : null);
