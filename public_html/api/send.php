<?php
/**
 * VisionID website mailer (contact form + ViDYA chat leads).
 * Recipients are fixed here on the server; the browser can never choose them.
 * Works on GoDaddy cPanel hosting with PHP 7.4+.
 */
declare(strict_types=1);

// ---- settings -------------------------------------------------------------
const RECIPIENTS = [
    'contact' => 'sales@visionid.in',     // contact form
    'lead'    => 'kalpana@visionid.in',   // ViDYA chat assistant
    'partner' => 'sales@visionid.in',     // partner applications
    'career'  => 'sales@visionid.in',     // job applications (e.g. careers@visionid.in)
];
const FROM_ADDRESS = 'website@visionid.in';  // must be an address on your own domain
const MAX_PER_WINDOW = 5;                    // submissions allowed per IP...
const WINDOW_SECONDS = 600;                  // ...per 10 minutes
const ALLOWED_FIELDS = ['Interest', 'Name', 'Organisation', 'Company', 'PartnerType', 'Region', 'Website', 'Role', 'Experience',
    'Location', 'Profile', 'Email', 'Mobile', 'City', 'Language', 'Note', 'Message', 'Source'];
// ---------------------------------------------------------------------------

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function respond(int $code, bool $ok, string $error = ''): void
{
    http_response_code($code);
    echo json_encode($error === '' ? ['ok' => $ok] : ['ok' => $ok, 'error' => $error]);
    exit;
}

function clean($value, int $max): string
{
    $s = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', (string) $value) ?? '';
    return mb_substr(trim(preg_replace('/\s+/u', ' ', $s) ?? ''), 0, $max);
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    respond(405, false, 'method');
}

// Same-origin only: reject posts from other websites.
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$host = strtolower(explode(':', $_SERVER['HTTP_HOST'] ?? '')[0]);
if ($origin !== '' && strtolower((string) parse_url($origin, PHP_URL_HOST)) !== $host) {
    respond(403, false, 'origin');
}

$raw = file_get_contents('php://input', false, null, 0, 20000);
$data = json_decode($raw === false ? '' : $raw, true);
if (!is_array($data)) {
    respond(400, false, 'format');
}

// Honeypot: bots fill hidden fields. Pretend success, send nothing.
if (!empty($data['website'])) {
    respond(200, true);
}

$kind = is_string($data['kind'] ?? null) ? $data['kind'] : '';
if (!isset(RECIPIENTS[$kind])) {
    respond(400, false, 'kind');
}

// Simple per-IP rate limit (file-based, no database needed).
$ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$bucket = sys_get_temp_dir() . '/visionid_rl_' . hash('sha256', $ip);
$now = time();
$hits = [];
if (is_file($bucket)) {
    $stored = json_decode((string) file_get_contents($bucket), true);
    if (is_array($stored)) {
        $hits = array_values(array_filter($stored, static fn($t) => is_int($t) && $t > $now - WINDOW_SECONDS));
    }
}
if (count($hits) >= MAX_PER_WINDOW) {
    respond(429, false, 'rate');
}
$hits[] = $now;
file_put_contents($bucket, json_encode($hits), LOCK_EX);

// Whitelisted, cleaned fields only.
$fields = is_array($data['fields'] ?? null) ? $data['fields'] : [];
$lines = [];
foreach (ALLOWED_FIELDS as $key) {
    if (isset($fields[$key]) && $fields[$key] !== '') {
        $lines[] = str_pad($key . ':', 14) . clean($fields[$key], $key === 'Message' || $key === 'Note' ? 1500 : 200);
    }
}
$email = clean($fields['Email'] ?? '', 120);
$name = clean($fields['Name'] ?? '', 80);
if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    respond(422, false, 'fields');
}

// Persist every accepted website enquiry centrally so it appears in the admin dashboard on any device.
try {
    $cfg = require __DIR__ . '/config.php';
    $db = new PDO('mysql:host=' . $cfg['db']['host'] . ';dbname=' . $cfg['db']['name'] . ';charset=utf8mb4', $cfg['db']['user'], $cfg['db']['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $phone = clean($fields['Mobile'] ?? '', 60);
    $message = clean($fields['Message'] ?? $fields['Note'] ?? '', 4000);
    if ($message === '') { $message = mb_substr(json_encode($fields, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?: '', 0, 4000); }
    $save = $db->prepare('INSERT INTO enquiries(kind,name,email,phone,message,fields_json,status,created_at,updated_at) VALUES(?,?,?,?,?,?,\'new\',NOW(),NOW())');
    $save->execute([$kind, $name, $email, $phone, $message, json_encode($fields, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)]);
} catch (Throwable $e) {
    respond(503, false, 'storage');
}

$subject = clean($data['subject'] ?? 'Website enquiry', 150);
$body = implode("\n", $lines)
    . "\n\n--\nSent from visionid.in on " . gmdate('Y-m-d H:i') . " UTC\n";

$headers = implode("\r\n", [
    'From: VisionID Website <' . FROM_ADDRESS . '>',
    'Reply-To: ' . $email,
    'MIME-Version: 1.0',
    'Content-Type: text/plain; charset=UTF-8',
    'Content-Transfer-Encoding: 8bit',
]);

$sent = mail(
    RECIPIENTS[$kind],
    '=?UTF-8?B?' . base64_encode($subject) . '?=',
    $body,
    $headers,
    '-f' . FROM_ADDRESS
);

// The enquiry is already saved centrally. Email is a notification, so a mail-server issue
// must not hide a successful database submission from the visitor.
respond(200, true);
