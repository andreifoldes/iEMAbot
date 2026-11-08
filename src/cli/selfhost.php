<?php
// Server side of the self-host kit (deploy/selfhost/). CLI-only; run as www-data inside the container.
//
//   php cli/selfhost.php apply-env        Set the address-dependent config from IEMABOT_PUBLIC_URL and
//                                         IEMABOT_CONTACT_EMAIL. Runs on every container start.
//   php cli/selfhost.php init <account>   Create the first admin account (password on stdin) and the push keys.
//                                         Exits with 3 when the server already has one.
//   php cli/selfhost.php set-password <account>   Set an account's new password (on stdin), and lift a login lockout.
//   php cli/selfhost.php db-check         Check the collected-data database for damage. Exits with 1 when it is damaged.
//   php cli/selfhost.php status           Print the server's state as key=value lines, without any secrets.

if(php_sapi_name() !== 'cli') {
	http_response_code(403);
	exit('This script is CLI-only.');
}

require_once dirname(__FILE__, 2) . '/backend/autoload.php';

use backend\Configs;
use backend\fileSystem\PathsFS;
use backend\FileSystemBasics;
use backend\Paths;
use backend\SelfHost;
use backend\sqlite\CollectedDataDb;
use Minishlink\WebPush\VAPID;

/** Applies the environment's values, writing the config file only when one of them changed. */
function applyEnv(): int {
	$publicUrl = (string) getenv(SelfHost::ENV_PUBLIC_URL);
	if($publicUrl === '') {
		fwrite(STDOUT, SelfHost::ENV_PUBLIC_URL . " is not set: leaving the server config as it is.\n");
		return 0;
	}
	try {
		$wanted = SelfHost::configFor($publicUrl, (string) getenv(SelfHost::ENV_CONTACT_EMAIL));
	}
	catch(InvalidArgumentException $e) {
		fwrite(STDERR, $e->getMessage() . "\n");
		return 1;
	}
	$changes = SelfHost::changedValues($wanted, Configs::getAll());
	if($changes) {
		FileSystemBasics::writeServerConfigs($changes);
		fwrite(STDOUT, 'Server config updated: ' . implode(', ', array_keys($changes)) . "\n");
	}
	return 0;
}

/** A data folder alone is not enough: an interrupted setup can leave one with no account to log in with. */
function hasAdmin(): bool {
	$store = Configs::getDataStore();
	return $store->isInit() && count($store->getAccountStore()->getAccountList()) > 0;
}

/** The password on stdin's first line, or null (after saying why) when it will not do. */
function readPassword(): ?string {
	$password = rtrim((string) fgets(STDIN), "\r\n");
	if(strlen($password) < 12) {
		fwrite(STDERR, "The password needs at least 12 characters.\n");
		return null;
	}
	return $password;
}

/** Creates the first admin account, the same way the first-run page does (see InitESMira). */
function init(array $args): int {
	$account = $args[0] ?? '';
	if($account === '') {
		fwrite(STDERR, "Usage: php cli/selfhost.php init <account> (password on stdin)\n");
		return 1;
	}
	if(hasAdmin()) {
		fwrite(STDOUT, "This server already has an account: nothing was changed.\n");
		return 3;
	}
	$store = Configs::getDataStore();
	$password = readPassword();
	if($password === null)
		return 1;
	
	// The cron jobs may already have touched the data folder, and the initializer would move a folder it finds in use.
	$_POST['data_location'] = DIR_BASE;
	$_POST['reuseFolder'] = '1';
	$initializer = $store->getESMiraInitializer();
	// The kit runs the participant web app and nothing else, so the dashboard should not offer native apps. A default only:
	// an admin can change it under Server settings, which is why this is not in apply-env.
	FileSystemBasics::writeServerConfigs(['webOnlyMode' => true] + $initializer->getConfigAdditions());
	try {
		$initializer->create($account, $password);
	}
	catch(Throwable $e) {
		// Like InitESMira: not "set up" after all. Only this key, because the address-dependent config must survive.
		FileSystemBasics::writeServerConfigs(['dataFolder_path' => '']);
		fwrite(STDERR, $e->getMessage() . "\n");
		return 1;
	}
	
	if(empty(Configs::get('vapid_public_key'))) {
		$keys = VAPID::createVapidKeys();
		$config = ['vapid_public_key' => $keys['publicKey'], 'vapid_private_key' => $keys['privateKey']];
		if(empty(Configs::get('vapid_subject')))
			$config['vapid_subject'] = 'mailto:noreply@esmira';
		FileSystemBasics::writeServerConfigs($config);
	}
	fwrite(STDOUT, "Created the admin account $account and the push keys.\n");
	return 0;
}

/** There is no email reset, so this is how an operator gets back into the dashboard. */
function setPassword(array $args): int {
	$account = $args[0] ?? '';
	$accounts = Configs::getDataStore()->getAccountStore();
	if($account === '' || !$accounts->doesAccountExist($account)) {
		fwrite(STDERR, "Which account? Usage: php cli/selfhost.php set-password <account> (password on stdin)\n");
		return 1;
	}
	$password = readPassword();
	if($password === null)
		return 1;
	$accounts->setAccount($account, $password); // Like the dashboard's own password change: also ends sessions and lockouts.
	fwrite(STDOUT, "Set a new password for $account.\n");
	return 0;
}

/** Damage to the database is silent until someone needs the data, so the kit's doctor.sh asks. Read-only. */
function dbCheck(): int {
	$path = PathsFS::folderData() . CollectedDataDb::FILENAME;
	if(!Configs::getDataStore()->isInit() || !file_exists($path)) {
		fwrite(STDOUT, "No data yet.\n");
		return 0;
	}
	try {
		$pdo = new PDO("sqlite:$path", null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::SQLITE_ATTR_OPEN_FLAGS => PDO::SQLITE_OPEN_READONLY]);
		$pdo->exec('PRAGMA busy_timeout = 5000');
		$result = (string) $pdo->query('PRAGMA quick_check')->fetchColumn();
	}
	catch(PDOException $e) {
		$result = $e->getMessage();
	}
	fwrite(STDOUT, "$result\n");
	return $result === 'ok' ? 0 : 1;
}

function status(): int {
	$isInit = Configs::getDataStore()->isInit();
	$heartbeatAge = null;
	if($isInit && file_exists(PathsFS::filePushHeartbeat()))
		$heartbeatAge = time() - filemtime(PathsFS::filePushHeartbeat());
	$status = [
		'version' => trim((string) @file_get_contents(Paths::FILE_SERVER_VERSION)),
		'dataVersion' => $isInit ? trim((string) @file_get_contents(PathsFS::folderData() . Paths::FILENAME_VERSION)) : '',
		'init' => hasAdmin() ? 'true' : 'false',
		'ready' => Configs::getDataStore()->isReady() ? 'true' : 'false',
		'pushKeys' => empty(Configs::get('vapid_public_key')) ? 'false' : 'true',
		'pushSenderLastRunSecondsAgo' => $heartbeatAge ?? '',
		'wearablesRedirectUri' => (string) Configs::get('wearables_redirect_uri'),
		'publicBaseUrl' => (string) Configs::get('channels_public_base_url'),
	];
	foreach($status as $key => $value)
		fwrite(STDOUT, "$key=$value\n");
	return 0;
}

$args = array_slice($argv ?? [], 1);
switch(array_shift($args)) {
	case 'apply-env':
		exit(applyEnv());
	case 'init':
		exit(init($args));
	case 'set-password':
		exit(setPassword($args));
	case 'db-check':
		exit(dbCheck());
	case 'status':
		exit(status());
	default:
		fwrite(STDERR, "Usage: php cli/selfhost.php apply-env | init <account> | set-password <account> | db-check | status\n");
		exit(1);
}
