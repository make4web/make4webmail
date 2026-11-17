<?php
/*
 * The webmail lives in public/. A server whose document root is the project's root (and does not
 * read the .htaccess next to this file) sends the visitor there: the address then shows /public/,
 * the one every page, cookie and app (Android) keeps.
 */
$dir = rtrim(str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/index.php'))), '/');
$query = (string) ($_SERVER['QUERY_STRING'] ?? '');
header('Location: ' . $dir . '/public/' . ($query !== '' ? '?' . $query : ''), true, 302);
header('Cache-Control: no-store');
