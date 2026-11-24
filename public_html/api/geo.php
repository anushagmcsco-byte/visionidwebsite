<?php
/**
 * Visitor region for ViDYA's language offer.
 * Reads Cloudflare's visitor-location headers (enable "Add visitor location headers" under
 * Rules > Transform Rules > Managed Transforms). Nothing is stored or logged.
 * Without Cloudflare it returns an empty object and ViDYA falls back to the browser language.
 */
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

$country = strtoupper(substr((string) ($_SERVER['HTTP_CF_IPCOUNTRY'] ?? ''), 0, 2));
$region  = substr(preg_replace('/[^\p{L} .\-]/u', '', (string) ($_SERVER['HTTP_CF_REGION'] ?? '')) ?? '', 0, 60);

echo json_encode($country === '' ? new stdClass() : ['country' => $country, 'region' => $region]);
