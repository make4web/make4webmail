<?php
/**
 * Fake mail provider for the test suite: a minimal IMAP server and an SMTP server
 * that check credentials and record everything (state in JSON files).
 *   php fake_provider.php <imapPort> <smtpPort> <stateFile> <smtpLog>
 * State: {"accounts": {"login": "password"}, "boxes": {"login": {"INBOX": {"validity": 1, "next": 1, "msgs": [{"uid":1,"raw":"..."}]}}}}
 */
[$_, $imapPort, $smtpPort, $stateFile, $smtpLog] = $argv;
$imap = stream_socket_server("tcp://127.0.0.1:$imapPort", $e1, $s1);
$smtp = stream_socket_server("tcp://127.0.0.1:$smtpPort", $e2, $s2);
if (!$imap || !$smtp) {
    fwrite(STDERR, "listen failed\n");
    exit(1);
}
$load = static fn() => json_decode((string) file_get_contents($stateFile), true);
$save = static fn(array $s) => file_put_contents($stateFile, json_encode($s), LOCK_EX);
$quoted = static function (string $s): array {
    preg_match_all('/"((?:[^"\\\\]|\\\\.)*)"|(\S+)/', $s, $m, PREG_SET_ORDER);
    return array_map(static fn($x) => isset($x[2]) ? $x[2] : stripcslashes($x[1]), $m);
};

while (true) {
    $r = [$imap, $smtp];
    $w = $x = null;
    if (!@stream_select($r, $w, $x, 60)) {
        continue;
    }
    foreach ($r as $srv) {
        $c = @stream_socket_accept($srv, 5);
        if (!$c) {
            continue;
        }
        stream_set_timeout($c, 10);
        $out = static fn(string $l) => fwrite($c, $l . "\r\n");
        if ($srv === $imap) {
            // ---------------------------------------------------------------- IMAP
            $out('* OK fake IMAP ready');
            $user = null;
            $box = null;
            while (($line = fgets($c)) !== false) {
                $line = rtrim($line, "\r\n");
                [$tag, $rest] = array_pad(explode(' ', $line, 2), 2, '');
                $cmd = strtoupper(strtok($rest, ' '));
                $args = trim(substr($rest, strlen($cmd)));
                $st = $load();
                if ($cmd === 'LOGIN') {
                    [$l, $p] = array_pad($quoted($args), 2, '');
                    if (($st['accounts'][$l] ?? null) === $p) {
                        $user = $l;
                        $out("$tag OK logged in");
                    } else {
                        $out("$tag NO [AUTHENTICATIONFAILED] invalid credentials");
                    }
                } elseif ($cmd === 'SELECT' && $user) {
                    $name = $quoted($args)[0] ?? 'INBOX';
                    if (!isset($st['boxes'][$user][$name])) {
                        $out("$tag NO no such mailbox");
                        continue;
                    }
                    $box = $name;
                    $f = $st['boxes'][$user][$box];
                    $out('* ' . count($f['msgs']) . ' EXISTS');
                    $out('* OK [UIDVALIDITY ' . $f['validity'] . '] UIDs valid');
                    $out("$tag OK [READ-WRITE] selected");
                } elseif ($cmd === 'UID' && $user && $box) {
                    $f = &$st['boxes'][$user][$box];
                    if (preg_match('/^SEARCH UID (\d+):\*/i', $args, $m)) {
                        $uids = array_map(static fn($x) => $x['uid'], array_filter($f['msgs'], static fn($x) => $x['uid'] >= (int) $m[1]));
                        $out('* SEARCH' . ($uids ? ' ' . implode(' ', $uids) : ''));
                        $out("$tag OK search done");
                    } elseif (preg_match('/^FETCH (\d+) /i', $args, $m)) {
                        foreach ($f['msgs'] as $i => $msg) {
                            if ($msg['uid'] === (int) $m[1]) {
                                $raw = str_replace("\n", "\r\n", str_replace("\r\n", "\n", $msg['raw']));
                                $meta = 'FLAGS (' . implode(' ', $msg['flags'] ?? []) . ') INTERNALDATE "' . date('d-M-Y H:i:s O', $msg['date'] ?? time()) . '" ';
                                fwrite($c, '* ' . ($i + 1) . ' FETCH (UID ' . $msg['uid'] . ' ' . $meta . 'BODY[] {' . strlen($raw) . "}\r\n" . $raw . ")\r\n");
                            }
                        }
                        $out("$tag OK fetch done");
                    } elseif (preg_match('/^STORE (\d+) \+FLAGS/i', $args, $m)) {
                        foreach ($f['msgs'] as &$msg) {
                            if ($msg['uid'] === (int) $m[1]) {
                                $msg['deleted'] = true;
                            }
                        }
                        unset($msg);
                        $save($st);
                        $out("$tag OK store done");
                    } else {
                        $out("$tag BAD unsupported");
                    }
                    unset($f);
                } elseif ($cmd === 'EXPUNGE' && $user && $box) {
                    $st['boxes'][$user][$box]['msgs'] = array_values(array_filter($st['boxes'][$user][$box]['msgs'], static fn($m) => empty($m['deleted'])));
                    $save($st);
                    $out("$tag OK expunged");
                } elseif ($cmd === 'LOGOUT') {
                    $out('* BYE');
                    $out("$tag OK bye");
                    break;
                } else {
                    $out("$tag BAD unknown command");
                }
            }
        } else {
            // ---------------------------------------------------------------- SMTP
            $out('220 fake SMTP ready');
            $sess = ['auth' => null, 'from' => null, 'rcpt' => [], 'data' => ''];
            $authed = null;
            while (($line = fgets($c)) !== false) {
                $l = rtrim($line, "\r\n");
                $u = strtoupper(substr($l, 0, 4));
                if ($u === 'EHLO' || $u === 'HELO') {
                    $out('250-fake');
                    $out('250-AUTH PLAIN LOGIN');
                    $out('250 SIZE 10000000');
                } elseif ($u === 'AUTH') {
                    $parts = explode("\0", base64_decode(substr($l, 11)));
                    $st = $load();
                    if (count($parts) === 3 && ($st['accounts'][$parts[1]] ?? null) === $parts[2]) {
                        $authed = $parts[1];
                        $out('235 2.7.0 authenticated');
                    } else {
                        $out('535 5.7.8 authentication failed');
                    }
                } elseif ($u === 'MAIL') {
                    preg_match('/<([^>]*)>/', $l, $m);
                    $from = $m[1] ?? '';
                    // Like real providers: only the authenticated mailbox may send.
                    $st = $load();
                    if ($authed === null) {
                        $out('530 5.7.0 authentication required');
                    } elseif (!empty($st['smtp_reject']) || !empty($st['smtp_reject_for'][$authed])) {
                        // Simulates the provider's own sending cap (all accounts, or one).
                        $out('450 4.7.1 ' . ($st['smtp_reject'] ?? $st['smtp_reject_for'][$authed]));
                    } elseif (strcasecmp($from, $authed) !== 0) {
                        $out('553 5.7.1 sender not allowed');
                    } else {
                        $sess = ['auth' => $authed, 'from' => $from, 'rcpt' => [], 'data' => ''];
                        $out('250 OK');
                    }
                } elseif ($u === 'RCPT') {
                    preg_match('/<([^>]*)>/', $l, $m);
                    $sess['rcpt'][] = $m[1] ?? '';
                    $out('250 OK');
                } elseif ($u === 'DATA') {
                    $out('354 go');
                    $data = '';
                    while (($dl = fgets($c)) !== false && $dl !== ".\r\n") {
                        $data .= $dl[0] === '.' ? substr($dl, 1) : $dl;
                    }
                    $sess['data'] = $data;
                    file_put_contents($smtpLog, json_encode($sess) . "\n", FILE_APPEND | LOCK_EX);
                    $out('250 queued');
                } elseif ($u === 'QUIT') {
                    $out('221 bye');
                    break;
                } else {
                    $out('250 OK');
                }
            }
        }
        fclose($c);
    }
}
