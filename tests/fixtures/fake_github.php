<?php
// Fake GitHub API for the update tests, run with the PHP built-in server:
//   php -S 127.0.0.1:<port> tests/fixtures/fake_github.php   (env FAKE_GH_DIR: the release folder)
// The token "github_pat_good_0123456789abcdef" is the only one accepted; the release is the
// content of $FAKE_GH_DIR/release (zipped on the fly), its version read from app/bootstrap.php.
$dir = getenv('FAKE_GH_DIR');
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
$json = static function ($d, int $code = 200) { http_response_code($code); header('Content-Type: application/json'); echo json_encode($d); exit; };
if ($path === '/download/release.zip') {
    // The download address of a zipball needs no token (GitHub signs it).
    $zipFile = $dir . '/out.zip';
    @unlink($zipFile);
    $z = new ZipArchive();
    $z->open($zipFile, ZipArchive::CREATE);
    $base = $dir . '/release';
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        $z->addFile($f->getPathname(), 'make4web-make4webmail-abc1234/' . substr($f->getPathname(), strlen($base) + 1));
    }
    $z->close();
    header('Content-Type: application/zip');
    readfile($zipFile);
    exit;
}
if ($auth !== 'Bearer github_pat_good_0123456789abcdef') {
    $json(['message' => 'Bad credentials'], 401);
}
$sha = trim((string) @file_get_contents($dir . '/sha')) ?: 'abc1234def5678abc1234def5678abc1234def56';
switch (true) {
    case $path === '/user':
        $json(['login' => 'make4web']);
    case $path === '/repos/make4web/make4webmail':
        $json(['full_name' => 'make4web/make4webmail', 'default_branch' => 'main', 'private' => true]);
    case $path === '/repos/make4web/make4webmail/commits':
        $json([['sha' => $sha, 'commit' => ['message' => "Nouvelle version\n\ndétails", 'committer' => ['date' => '2026-10-17T10:00:00Z'], 'author' => ['name' => 'Christophe']]]]);
    case $path === '/repos/make4web/make4webmail/contents/app/bootstrap.php':
        header('Content-Type: text/plain');
        readfile($dir . '/release/app/bootstrap.php');
        exit;
    case str_starts_with($path, '/repos/make4web/make4webmail/zipball/'):
        header('Location: http://' . $_SERVER['HTTP_HOST'] . '/download/release.zip', true, 302);
        exit;
}
$json(['message' => 'Not Found'], 404);
