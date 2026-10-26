<?php
declare(strict_types=1);
// Opt-in integration smoke test: saves current values only, never a new limit.
if (PHP_SAPI !== 'cli' || !getenv('OEE_TEST_BASE_URL')) { exit("Set OEE_TEST_BASE_URL to run the authenticated threshold API smoke test.\n"); }
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/security.php';
$pdo = db();
$sessions = [];
$check = static function (bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); };
$session = static function (array $user) use (&$sessions): array {
    session_id(''); start_app_session();
    $_SESSION = ['user_id' => (int) $user['id'], 'auth_version' => (int) $user['auth_version']];
    $token = csrf_token();
    $cookie = session_name() . '=' . session_id();
    $file = (session_save_path() ?: sys_get_temp_dir()) . '/sess_' . session_id();
    session_write_close();
    $sessions[] = $file;
    if ($owner = getenv('OEE_TEST_SESSION_OWNER')) chown($file, $owner);
    return [$cookie, $token];
};
$call = static function (string $action, ?array $auth = null, ?array $body = null, bool $csrf = true): array {
    $curl = curl_init(rtrim(getenv('OEE_TEST_BASE_URL'), '/') . '/api.php?resource=oee&action=' . $action);
    $headers = ['Accept: application/json'];
    if ($body !== null) $headers[] = 'Content-Type: application/json';
    if ($auth && $csrf) $headers[] = 'X-CSRF-Token: ' . $auth[1];
    curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30, CURLOPT_HTTPHEADER => $headers]);
    if ($auth) curl_setopt($curl, CURLOPT_COOKIE, $auth[0]);
    if ($body !== null) curl_setopt_array($curl, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($body)]);
    $response = curl_exec($curl); $status = curl_getinfo($curl, CURLINFO_HTTP_CODE); curl_close($curl);
    return [$status, is_string($response) ? json_decode($response, true, flags: JSON_THROW_ON_ERROR) : null];
};
try {
    $admin = $pdo->query("SELECT id,auth_version FROM portal_users WHERE is_active=1 AND role='admin' ORDER BY id LIMIT 1")->fetch();
    $check((bool) $admin, 'Active admin required for smoke test');
    $auth = $session($admin);
    [$status, $before] = $call('masters', $auth);
    $check($status === 200 && count($before['thresholds']) === 3, 'Masters must list all three plants');
    $sample = ['master_type' => 'threshold', 'line_code' => 'APP-06', 'gap_threshold_minutes' => 20];
    $check($call('masters')[0] === 401, 'Anonymous read must fail');
    $check($call('master-save', null, $sample)[0] === 401, 'Anonymous write must fail');
    $check($call('master-save', $auth, $sample, false)[0] === 419, 'Missing CSRF must fail');
    foreach ([0, -1, null, [], true, 'abc', 1441, 20.0001] as $invalid) {
        $check($call('master-save', $auth, array_replace($sample, ['gap_threshold_minutes' => $invalid]))[0] === 422, 'Invalid limit must return 422');
    }
    $check($call('master-save', $auth, array_replace($sample, ['line_code' => 'ALL']))[0] === 422, 'ALL must return 422');
    $user = $pdo->query("SELECT id,auth_version FROM portal_users WHERE is_active=1 AND role='user' ORDER BY id LIMIT 1")->fetch();
    if ($user) $check($call('master-save', $session($user), $sample)[0] === 403, 'Non-admin write must fail');
    else echo "No active non-admin account; non-admin HTTP check skipped (read-only UI covered in browser test).\n";
    foreach ($before['thresholds'] as $plant) {
        [$status, $saved] = $call('master-save', $auth, array_merge($sample, $plant));
        $check($status === 200 && $saved['ok'] === true && (float) $saved['gap_threshold_minutes'] === (float) $plant['gap_threshold_minutes'], 'Saving current plant value failed');
    }
    [, $after] = $call('masters', $auth);
    $check($before['thresholds'] === $after['thresholds'], 'Smoke test changed configured threshold values');
    echo "Threshold HTTP smoke passed: defaults/read/save/reload, validation, authentication and CSRF.\n";
} finally {
    foreach ($sessions as $file) if (is_file($file)) unlink($file);
}
