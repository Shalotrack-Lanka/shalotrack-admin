<?php

namespace App\Support;

/**
 * Turns a browser's User-Agent text into "Chrome 141 on Windows, Desktop" for the audit log.
 *
 * Built from a fixed vocabulary on purpose: the User-Agent is typed by the client and can contain
 * anything, so only known browser / system names, digits and (for the model) a tightly filtered
 * token ever come out. Raw text is never echoed into a screen or a CSV from here.
 *
 * What a User-Agent can and cannot tell you (browsers have been trimming it for privacy):
 *  - Browser name and major version: reliable.
 *  - Device class (Desktop / Mobile / Tablet): reliable enough; an Android tablet and "desktop site"
 *    mode on an Android phone look the same.
 *  - Windows 10 and 11 both say "Windows NT 10.0"; macOS always says 10_15_7; Chrome on Android
 *    always says Android 10 and hides the phone model. So those versions are NOT shown, and the
 *    model appears only when the browser volunteers it (Samsung Internet, Firefox, iPhone / iPad).
 *  - It is client-supplied, so treat it as a clue for following activity, never as proof.
 */
class UserAgent
{
    /** @return array{browser:string,os:string,device:string,model:?string} */
    public static function parse(?string $ua): array
    {
        $ua = trim((string) $ua);
        if ($ua === '') {
            return ['browser' => 'Unknown', 'os' => 'Unknown', 'device' => 'Unknown', 'model' => null];
        }

        // Scripts and crawlers first: a script that copies a browser's text still says so in its own way.
        if (preg_match('/\b\w*bot\b|crawler|spider|slurp/i', $ua)) {
            return ['browser' => 'Search / web bot', 'os' => 'Unknown', 'device' => 'Bot', 'model' => null];
        }
        if (preg_match('/^(curl|Wget|python-requests|python-urllib|PostmanRuntime|insomnia|okhttp|Go-http-client|axios|node-fetch|Java|libwww-perl|HTTPie)\b[\/ ]?([\d]+)?/i', $ua, $m)) {
            return ['browser' => self::toolName($m[1]) . (isset($m[2]) && $m[2] !== '' ? ' ' . $m[2] : ''), 'os' => 'Unknown', 'device' => 'Script / tool', 'model' => null];
        }

        return [
            'browser' => self::browser($ua),
            'os'      => self::os($ua),
            'device'  => self::device($ua),
            'model'   => self::model($ua),
        ];
    }

    /** "Chrome 141 on Windows 10/11, Desktop" (or "Unknown" when there is nothing to show). */
    public static function label(?string $ua): string
    {
        $p = self::parse($ua);
        if ($p['browser'] === 'Unknown' && $p['os'] === 'Unknown' && $p['device'] === 'Unknown') {
            return 'Unknown device';
        }

        $os = $p['os'] === 'Unknown' ? '' : ' on ' . $p['os'];
        $model = $p['model'] ? ' (' . $p['model'] . ')' : '';

        return $p['browser'] . $os . $model . ($p['device'] === 'Unknown' ? '' : ', ' . $p['device']);
    }

    private static function toolName(string $raw): string
    {
        $map = ['curl' => 'curl', 'wget' => 'Wget', 'python-requests' => 'Python script', 'python-urllib' => 'Python script',
            'postmanruntime' => 'Postman', 'insomnia' => 'Insomnia', 'okhttp' => 'OkHttp client', 'go-http-client' => 'Go client',
            'axios' => 'Node script', 'node-fetch' => 'Node script', 'java' => 'Java client', 'libwww-perl' => 'Perl script', 'httpie' => 'HTTPie'];

        return $map[strtolower($raw)] ?? 'Script';
    }

    private static function major(string $version): string
    {
        return explode('.', $version)[0];
    }

    private static function browser(string $ua): string
    {
        // Order matters: Edge, Opera and Samsung Internet all also say "Chrome", and Chrome also says "Safari".
        $rules = [
            ['/\bEdg(?:e|A|iOS)?\/([\d.]+)/', 'Edge'],
            ['/\b(?:OPR|Opera)\/([\d.]+)/', 'Opera'],
            ['/SamsungBrowser\/([\d.]+)/', 'Samsung Internet'],
            ['/\bUCBrowser\/([\d.]+)/', 'UC Browser'],
            ['/\b(?:Firefox|FxiOS)\/([\d.]+)/', 'Firefox'],
        ];
        foreach ($rules as [$re, $name]) {
            if (preg_match($re, $ua, $m)) {
                return $name . ' ' . self::major($m[1]);
            }
        }

        // An app's built-in web view, not a normal browser.
        if (preg_match('/\bwv\b\)|; wv\)/', $ua)) {
            return 'Android WebView';
        }
        if (preg_match('/\b(FBAN|FBAV|FB_IAB)\b/', $ua)) {
            return 'Facebook in-app browser';
        }
        if (stripos($ua, 'Instagram') !== false) {
            return 'Instagram in-app browser';
        }

        if (preg_match('/\b(?:Chrome|CriOS)\/([\d.]+)/', $ua, $m)) {
            return 'Chrome ' . self::major($m[1]);
        }
        if (preg_match('/Version\/([\d.]+).*Safari\//', $ua, $m)) {
            return 'Safari ' . self::major($m[1]);
        }
        if (preg_match('/MSIE ([\d.]+)/', $ua, $m) || preg_match('/Trident\/.*rv:([\d.]+)/', $ua, $m)) {
            return 'Internet Explorer ' . self::major($m[1]);
        }

        return 'Unknown';
    }

    private static function os(string $ua): string
    {
        if (preg_match('/\b(?:iPhone|iPad|iPod)\b.*?\bOS ([\d_]+)/', $ua, $m)) {
            return 'iOS ' . explode('_', $m[1])[0];
        }
        if (stripos($ua, 'Android') !== false) {
            return 'Android'; // Chrome freezes the version at 10, so it would mislead
        }
        if (preg_match('/Windows NT ([\d.]+)/', $ua, $m)) {
            return match ($m[1]) {
                '10.0' => 'Windows 10/11', // the two cannot be told apart
                '6.3'  => 'Windows 8.1',
                '6.2'  => 'Windows 8',
                '6.1'  => 'Windows 7',
                default => 'Windows',
            };
        }
        if (stripos($ua, 'CrOS') !== false) {
            return 'ChromeOS';
        }
        if (stripos($ua, 'Macintosh') !== false || stripos($ua, 'Mac OS X') !== false) {
            return 'macOS';
        }
        if (stripos($ua, 'Linux') !== false || stripos($ua, 'X11') !== false) {
            return 'Linux';
        }

        return 'Unknown';
    }

    private static function device(string $ua): string
    {
        if (preg_match('/\b(iPad|Tablet|Kindle|Silk)\b/i', $ua)) {
            return 'Tablet';
        }
        if (preg_match('/\bAndroid\b/i', $ua)) {
            return preg_match('/\bMobile\b/i', $ua) ? 'Mobile' : 'Tablet';
        }
        if (preg_match('/\b(iPhone|iPod|Mobile|Windows Phone)\b/i', $ua)) {
            return 'Mobile';
        }
        if (preg_match('/Windows NT|Macintosh|Mac OS X|CrOS|Linux|X11/i', $ua)) {
            return 'Desktop';
        }

        return 'Unknown';
    }

    /** Only what the browser volunteers: iPhone / iPad, or an Android model such as SM-S918B. */
    private static function model(string $ua): ?string
    {
        if (stripos($ua, 'iPhone') !== false) {
            return 'iPhone';
        }
        if (stripos($ua, 'iPad') !== false) {
            return 'iPad';
        }
        if (preg_match('/Android [\d.]+; ([^;)]{1,40})/', $ua, $m)) {
            $model = trim($m[1]);
            // "K" is Chrome's placeholder; Mobile / Tablet / wv are flags, not models.
            if (! in_array(strtolower($model), ['k', 'mobile', 'tablet', 'wv', ''], true) && preg_match('/^[A-Za-z0-9][A-Za-z0-9 _.\-]{0,39}$/', $model)) {
                return $model;
            }
        }

        return null;
    }
}