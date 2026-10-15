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
    'outbound_proxy' => '',  // e.g. 'http://proxy.lan:3128' when the server can only reach the Internet through a proxy (pictures of messages)
    'session_store' => 'database', // 'files' to keep PHP sessions in storage/sessions (one web server only)
    'bimi_roots' => '',      // PEM file of trusted mark certificate authorities for BIMI (default: app/Data/bimi-roots.pem, DigiCert)
    'inbound'      => ['tls_cert' => '', 'tls_key' => ''], // STARTTLS for bin/smtpd.php
];
