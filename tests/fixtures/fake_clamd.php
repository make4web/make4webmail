<?php
/**
 * Fake clamd for the test suite (INSTREAM, PING, VERSION over TCP).
 *   php fake_clamd.php <port>
 * Reports the EICAR test string as "Eicar-Test-Signature".
 */
$srv = stream_socket_server('tcp://127.0.0.1:' . (int) $argv[1], $e, $s);
if (!$srv) {
    exit(1);
}
while ($c = @stream_socket_accept($srv, -1)) {
    stream_set_timeout($c, 10);
    $cmd = '';
    while (($ch = fread($c, 1)) !== false && $ch !== '' && $ch !== "\0") {
        $cmd .= $ch;
    }
    if ($cmd === 'zPING') {
        fwrite($c, "PONG\0");
    } elseif ($cmd === 'zVERSION') {
        fwrite($c, "ClamAV 1.4.0/fake\0");
    } elseif ($cmd === 'zINSTREAM') {
        $data = '';
        while (true) {
            $len = fread($c, 4);
            if (strlen($len) < 4) {
                break;
            }
            $n = unpack('N', $len)[1];
            if ($n === 0) {
                break;
            }
            $chunk = '';
            while (strlen($chunk) < $n && !feof($c)) {
                $chunk .= fread($c, $n - strlen($chunk));
            }
            $data .= $chunk;
        }
        fwrite($c, str_contains($data, 'EICAR-STANDARD-ANTIVIRUS-TEST-FILE') ? "stream: Eicar-Test-Signature FOUND\0" : "stream: OK\0");
    }
    fclose($c);
}
