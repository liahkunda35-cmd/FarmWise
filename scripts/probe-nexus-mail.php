<?php
require dirname(__DIR__) . '/backend/bootstrap.php';

echo 'ca=' . (SslCa::caInfoPath() ?: 'none') . PHP_EOL;
echo 'ca_size=' . (SslCa::caInfoPath() && is_file(SslCa::caInfoPath()) ? filesize(SslCa::caInfoPath()) : 0) . PHP_EOL;
echo 'hub=' . (NexusMailer::isHub() ? 'yes' : 'no') . PHP_EOL;
echo 'from=[' . NexusMailer::fromEmail() . ']' . PHP_EOL;
echo 'base=' . NexusMailer::baseUrl() . PHP_EOL;

$r = NexusMailer::send(
    'liahkunda44@gmail.com',
    'FarmWise employee login test',
    '<p>This is a FarmWise delivery probe.</p>',
    "This is a FarmWise delivery probe.\nIf you receive this, Nexus email works."
);
echo json_encode([
    'ok' => !empty($r['ok']),
    'error' => $r['error'] ?? null,
    'http' => $r['http'] ?? null,
    'provider' => $r['provider'] ?? null,
    'status' => $r['status'] ?? null,
    'mode' => $r['mode'] ?? null,
    'endpoint' => $r['endpoint'] ?? null,
], JSON_PRETTY_PRINT) . PHP_EOL;
