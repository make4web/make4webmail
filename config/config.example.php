<?php
// Copy to config/config.php or use the web installer (/install) / `php bin/install.php`.
return [
    'debug'        => false,
    'timezone'     => 'Europe/Paris',
    'storage_path' => __DIR__ . '/../storage',
    'db'           => ['driver' => 'sqlite', 'path' => __DIR__ . '/../storage/data/m4w.sqlite'],
    // 'db'        => ['driver' => 'mysql', 'host' => '127.0.0.1', 'port' => 3306, 'name' => 'm4w', 'user' => 'm4w', 'pass' => 'secret'],
    'app_key'      => '', // base64_encode(random_bytes(32))
    'base_url'     => '', // e.g. https://mail.example.com (only needed behind some proxies / sub-directories)
    'trusted_proxies' => [], // e.g. ['127.0.0.1'] when behind nginx/haproxy
    'inbound'      => ['tls_cert' => '', 'tls_key' => ''], // STARTTLS for bin/smtpd.php
];
