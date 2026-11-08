<?php

namespace backend;

use backend\exceptions\CriticalException;
use Throwable;

const IS_TEST_INSTANCE = PHP_SAPI == 'cli';

class Main {
    const SERVER_VERSION = 12,
        ACCEPTED_SERVER_VERSION = 7;
    public static $defaultPostInput = ''; //only used for testing

    /**
     * @throws CriticalException
     */
    static function sessionStart(): void {
        switch (session_status()) {
            case PHP_SESSION_ACTIVE:
                break;
            case PHP_SESSION_NONE:
                if (!IS_TEST_INSTANCE) {
                    session_set_cookie_params([
                        'secure' => self::isHttps(),
                        'httponly' => true,
                        'samesite' => 'Strict'
                    ] + session_get_cookie_params());
                    session_start();
                }
                break;
            case PHP_SESSION_DISABLED:
            default:
                throw new CriticalException('This server does not support sessions!');
        }
    }

    /** The HTTP status the last setStatus() asked for (tests read it; PHP refuses to set a status once output has started). */
    public static ?int $status = null;

    static function setStatus(int $code): void {
        self::$status = $code;
        if (IS_TEST_INSTANCE)
            return;
        http_response_code($code);
    }

    static function setHeader($header): void {
        if (IS_TEST_INSTANCE)
            return;
        header($header);
    }

    static function getMilliseconds(): int {
        return function_exists('microtime') ? ((int)round(microtime(true) * 1000)) : time() * 1000;
    }

    static function reportError(Throwable $e, string $msg = 'Server had an error:') {
        self::report("$msg\n" . $e->getFile() . '(' . $e->getLine() . '): ' . $e->getMessage() . "\n" . $e->getTraceAsString());
    }
    static function report(string $msg): bool {
        return Configs::getDataStore()->getErrorReportStore()->saveErrorReport($msg);
    }
    /**
     * Language codes are used to build file names (studies/<id>/.langs/<code>.json), so they may only
     * contain letters, digits, "_" and "-". Everything else (".", "/", "\\", ...) is removed.
     */
    static function sanitizeLangCode($lang): string {
        return substr(preg_replace('/[^A-Za-z0-9_-]/', '', is_string($lang) ? $lang : ''), 0, 20);
    }

    static function getLang(bool $limitLang = true): string {
        if (isset($_GET['lang'])) {
            $lang = self::sanitizeLangCode($_GET['lang']);
            $expires = 32532447600;
            if (gettype($expires) == "double") // check in case of 32 bit OS
                $expires = -1;
            self::setCookie('lang', $lang, $expires);
        } elseif (isset($_COOKIE['lang']))
            $lang = self::sanitizeLangCode($_COOKIE['lang']);
        elseif (isset($_SERVER['HTTP_ACCEPT_LANGUAGE'])) {
            $lang = self::sanitizeLangCode(substr($_SERVER['HTTP_ACCEPT_LANGUAGE'], 0, 2));
        } else $lang = Configs::get('defaultLang') ?: 'en';

        if ($limitLang) {
            switch ($lang) {
                case 'cs':
                case 'da':
                case 'de':
                case 'el':
                case 'en':
                case 'es':
                case 'fi':
                case 'fr':
                case 'he':
                case 'hi':
                case 'it':
                case 'ja':
                case 'ko':
                case 'nb_NO':
                case 'nl':
                case 'pl':
                case 'pt':
                case 'sv':
                case 'tl':
                case 'tr':
                case 'uk':
                case 'zk':
                    return $lang;
                default:
                    return 'en';
            }
        }
        return $lang;
    }

    static function getRawPostInput(): string {
        return IS_TEST_INSTANCE ? self::$defaultPostInput : file_get_contents('php://input');
    }

    static function getAccessKey(): string {
        if (isset($_GET['key']) && self::strictCheckInput($_GET['key'])) {
            $key = strtolower(trim($_GET['key']));
            self::setCookie('accessKey', $key);
            if (strlen($key))
                return $key;
        } elseif (isset($_COOKIE['accessKey']) && self::strictCheckInput($_COOKIE['accessKey'])) {
            $key = strtolower($_COOKIE['accessKey']);
            if (strlen($key))
                return $key;
        }

        return '';
    }

    /**
     * Encodes a value as a JavaScript literal (a JSON string/number/bool). JSON_HEX_* turns < > & ' "
     * inside strings into \uXXXX escapes, so request data can neither end the JS string nor close a
     * <script> element. To place the result in an HTML attribute (onload="...") also pass the whole
     * attribute value through htmlspecialchars(), because the literal's own delimiters are double quotes.
     */
    static function jsLiteral($value): string {
        return json_encode($value, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    /**
     * True when the browser talks HTTPS to us, directly or through a TLS-terminating reverse proxy.
     */
    static function isHttps(): bool {
        $https = $_SERVER['HTTPS'] ?? '';
        if ($https !== '' && strtolower((string)$https) !== 'off')
            return true;
        return strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
    }

    /**
     * @param bool $httpOnly Set true for credentials (login tokens): JavaScript on the page can then not read them.
     */
    // TODO: LIVE CHECK V1, rest (needs a browser): log in with "remember me", close the browser fully, reopen the admin address, then follow
    // an admin link from an email or chat. Still logged in after reopening; no admin action from the external link. Tracker Part 1.
    // Done 2026-11-06: no login cookie is readable from page script (HttpOnly).
    static function setCookie(string $key, string $value, int $expires = -1, bool $httpOnly = false) {
        if ($expires == -1)
            $expires = time() + 15552000;
        $_COOKIE[$key] = $value;
        if (IS_TEST_INSTANCE)
            return;
        if (version_compare(phpversion(), '7.3', '<'))
            setcookie($key, $value, $expires);
        else setcookie($key, $value, [
            'expires' => $expires,
            'secure' => self::isHttps(),
            'httponly' => $httpOnly,
            'samesite' => 'Strict'
        ]);
    }

    /**
     * Cross-site request forgery guard for state-changing / data-reading endpoints that rely on cookies.
     * Browsers label every request with Sec-Fetch-Site (Origin as fallback for older ones); only
     * requests that start on our own origin (or on nothing, e.g. a typed URL) are allowed. Clients that
     * send neither header (scripts, curl) are not browsers and cannot be tricked into riding a session.
     */
    static function isCrossSiteRequest(): bool {
        $site = $_SERVER['HTTP_SEC_FETCH_SITE'] ?? null;
        if (is_string($site) && $site !== '')
            return !in_array(strtolower($site), ['same-origin', 'none'], true);

        $origin = $_SERVER['HTTP_ORIGIN'] ?? null;
        if (!is_string($origin) || $origin === '' || $origin === 'null')
            return $origin === 'null';
        $originHost = parse_url($origin, PHP_URL_HOST);
        if (!$originHost)
            return true;
        $originPort = parse_url($origin, PHP_URL_PORT);
        $originHostPort = strtolower($originHost) . ($originPort ? ":$originPort" : '');
        foreach (['HTTP_HOST', 'HTTP_X_FORWARDED_HOST'] as $header) {
            $host = $_SERVER[$header] ?? null;
            if (is_string($host) && strtolower(trim(explode(',', $host)[0])) === $originHostPort)
                return false;
        }
        return true;
    }
    static function deleteCookie($name) {
        self::setCookie($name, '', 1);
        unset($_COOKIE[$name]);
    }

    public static function strictCheckInput($s): bool {
        //Thanks to: https://dev.to/tillsanders/let-s-stop-using-a-za-z-4a0m
        return empty($s) || (strlen($s) < Configs::get('user_input_max_length') && preg_match('/^[\p{L}\p{M}\p{N}_\'\-().\s]+$/ui', $s));
    }

    static function arrayToCSV(array $data, string $csvDelimiter): string {
        return '"' . implode('"' . $csvDelimiter . '"', $data) . '"';
    }
}