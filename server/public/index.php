<?php
// Exponential Network API v1 front controller (self-host mode, or behind the network.se7enx.com vhost):
// the request goes to Xne\Server\Api. Data folder: XNE_SERVER_DATA (default /var/lib/xne-server).
declare(strict_types=1);
$home = dirname(__DIR__, 2);
require $home . '/lib/Xne/Autoload.php';
\Xne\Autoload::register($home);
$data = getenv('XNE_SERVER_DATA') ?: '/var/lib/xne-server';
$headers = [];
foreach ($_SERVER as $k => $v) {
    if (strncmp($k, 'HTTP_', 5) === 0) {
        $headers[strtolower(str_replace('_', '-', substr($k, 5)))] = (string) $v;
    }
}
$body = (string) file_get_contents('php://input', false, null, 0, 1048576);
try {
    [$status, $out] = (new \Xne\Server\Api(new \Xne\Server\Store($data)))->handle((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'), (string) ($_SERVER['REQUEST_URI'] ?? '/'), $headers, $body);
} catch (\Throwable $e) {
    [$status, $out] = [500, ['ok' => false, 'error' => 'internal error']];
}
http_response_code($status);
header('Content-Type: application/json');
header('Cache-Control: no-store');
echo \Xne\Json::encode($out), "\n";
