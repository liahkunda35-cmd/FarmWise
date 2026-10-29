<?php
declare(strict_types=1);
echo 'curl.cainfo=' . ini_get('curl.cainfo') . PHP_EOL;
echo 'openssl.cafile=' . ini_get('openssl.cafile') . PHP_EOL;
print_r(openssl_get_cert_locations());
$urls = ['https://app.nexuspromail.com', 'https://curl.se'];
$cainfo = dirname(__DIR__) . '/storage/certs/cacert.pem';
foreach ($urls as $url) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_NOBODY => true,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);
    curl_exec($ch);
    echo "default $url http=" . curl_getinfo($ch, CURLINFO_HTTP_CODE) . ' err=' . curl_error($ch) . PHP_EOL;
    curl_close($ch);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_NOBODY => true,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_CAINFO => $cainfo,
    ]);
    curl_exec($ch);
    echo "cacert  $url http=" . curl_getinfo($ch, CURLINFO_HTTP_CODE) . ' err=' . curl_error($ch) . PHP_EOL;
    curl_close($ch);
}
