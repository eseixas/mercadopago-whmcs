<?php

declare(strict_types=1);

// Isolated HTTP tests of the actual pay.php/process.php, with no WHMCS or MP API.
define('WHMCS', true);
require_once dirname(__DIR__, 2) . '/modules/gateways/seixastec_mercadopago/CheckoutCsrf.php';
use WHMCS\Module\Gateway\SeixastecMercadoPago\CheckoutCsrf;

$passed = 0;
function check(bool $condition, string $label): void
{
    global $passed;
    if (!$condition) {
        throw new RuntimeException('FAIL: ' . $label);
    }
    ++$passed;
    echo 'OK ' . $label . PHP_EOL;
}

$a = ['uid' => 101];
$token = CheckoutCsrf::token($a);
check(strlen($token) === 64, '256-bit session token');
check(CheckoutCsrf::token($a) === $token, 'multiple tabs reuse token');
$b = ['uid' => 101];
check(CheckoutCsrf::token($b) !== $token, 'independent session token');
foreach ([null, '', [], 1, str_repeat('0', 64)] as $bad) {
    check(!CheckoutCsrf::valid($a, $bad), 'reject malformed/missing/wrong token: ' . get_debug_type($bad));
}
check(!CheckoutCsrf::valid($b, $token), 'other-session token rejected');
check(!CheckoutCsrf::valid([], $token), 'expired session rejected');
$a['uid'] = 202;
check(!CheckoutCsrf::valid($a, $token), 'client switch rejected');
check(CheckoutCsrf::token($a) !== $token, 'client switch rotates token');

$root = sys_get_temp_dir() . '/mp_csrf_' . bin2hex(random_bytes(8));
$module = $root . '/modules/gateways/seixastec_mercadopago';
mkdir($module, 0700, true);
mkdir($root . '/sessions', 0700);
mkdir($root . '/includes', 0700);
$source = dirname(__DIR__, 2) . '/modules/gateways/seixastec_mercadopago';
foreach (['pay.php', 'process.php', 'CheckoutCsrf.php', 'constants.php', 'InvoiceAmount.php', 'Validator.php', 'BrazilAddress.php'] as $name) {
    copy($source . '/' . $name, $module . '/' . $name);
}
foreach (['Api.php', 'TransactionStore.php'] as $name) {
    copy(__DIR__ . '/' . $name, $module . '/' . $name);
}
copy(__DIR__ . '/whmcs.php', $root . '/init.php');
copy(__DIR__ . '/router.php', $root . '/router.php');
file_put_contents($root . '/includes/gatewayfunctions.php', '<?php');
file_put_contents($root . '/includes/invoicefunctions.php', '<?php');
$socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
if (!$socket) {
    throw new RuntimeException($errstr);
}
$address = stream_socket_get_name($socket, false);
fclose($socket);
$server = proc_open(
    [PHP_BINARY, '-d', 'display_errors=0', '-d', 'disable_functions=curl_exec,curl_multi_exec',
    '-S', $address, '-t', $root, $root . '/router.php'],
    [0 => ['pipe', 'r'], 1 => ['file', $root . '/server.log', 'a'], 2 => ['file', $root . '/server.log', 'a']],
    $pipes,
    $root
);
if (!is_resource($server)) {
    throw new RuntimeException('Could not start isolated server');
}
$base = 'http://' . $address;
function request(string $path, string &$cookie, ?array $payload = null, string $method = 'GET', bool $ajax = true): array
{
    global $base;
    $headers = ['Content-Type: application/json', 'Cookie: ' . $cookie];
    if ($ajax) {
        $headers[] = 'X-Requested-With: XMLHttpRequest';
    }
    $ctx = stream_context_create(['http' => ['method' => $method, 'header' => implode("\r\n", $headers),
        'content' => $payload === null ? '' : json_encode($payload), 'ignore_errors' => true, 'timeout' => 5]]);
    $body = file_get_contents($base . $path, false, $ctx);
    foreach ($http_response_header as $header) {
        if (preg_match('/^Set-Cookie: ([^;]+)/i', $header, $match)) {
            $cookie = $match[1];
        }
    }
    preg_match('/\s(\d{3})\s/', $http_response_header[0], $match);
    return [(int) $match[1], $body, json_decode($body, true)];
}
try {
    for ($i = 0; $i < 50; ++$i) {
        $ready = @stream_socket_client('tcp://' . $address, $errno, $errstr, 0.1);
        if ($ready) {
            fclose($ready);
            break;
        }
        usleep(100000);
    }
    $cookie = '';
    $other = '';
    $token = request('/session', $cookie)[2]['token'];
    $otherToken = request('/session', $other)[2]['token'];
    $payload = ['invoice_id' => 1, 'payment_method' => 'pix', 'csrf_token' => $token, 'form_data' => []];
    $rejections = [
        ['missing token', array_diff_key($payload, ['csrf_token' => 1]), 403, 'POST', true],
        ['wrong token', array_replace($payload, ['csrf_token' => str_repeat('0', 64)]), 403, 'POST', true],
        ['other session', array_replace($payload, ['csrf_token' => $otherToken]), 403, 'POST', true],
        ['array token', array_replace($payload, ['csrf_token' => []]), 403, 'POST', true],
        ['card token is not CSRF', array_replace($payload, ['payment_method' => 'credit_card',
            'csrf_token' => 'CARD-TOKEN', 'form_data' => ['token' => 'CARD-TOKEN', 'payment_method_id' => 'visa']]), 403, 'POST', true],
        ['invoice ownership', array_replace($payload, ['invoice_id' => 2]), 404, 'POST', true],
        ['cancelled invoice', array_replace($payload, ['invoice_id' => 4]), 400, 'POST', true],
        ['missing AJAX header', $payload, 400, 'POST', false],
        ['GET forbidden', null, 405, 'GET', true],
    ];
    foreach ($rejections as [$label, $body, $status, $method, $ajax]) {
        $response = request('/process', $cookie, $body, $method, $ajax);
        check($response[0] === $status && $response[2]['success'] === false, $label . ' HTTP ' . $status);
        check(request('/trace', $cookie)[2] === [], $label . ' zero simulated API calls');
    }
    $expired = $other;
    request('/expire', $expired);
    check(request('/process', $expired, $payload, 'POST')[0] === 401, 'expired session HTTP 401');
    check(request('/trace', $cookie)[2] === [], 'expired session zero simulated API calls');
    $paid = request('/process', $cookie, array_replace($payload, ['invoice_id' => 3]), 'POST');
    check($paid[2]['success'] === true && request('/trace', $cookie)[2] === [], 'paid invoice does not create payment');
    $page = request('/pay?invoiceid=1', $cookie);
    check($page[0] === 200 && str_contains($page[1], 'csrf_token: "' . $token . '"'), 'actual checkout embeds matching token');
    $page2 = request('/pay?invoiceid=1', $cookie);
    check(str_contains($page2[1], 'csrf_token: "' . $token . '"'), 'second checkout tab keeps first token valid');
    foreach (['pix', 'ticket', 'credit_card', 'debit_card', 'pix'] as $index => $method) {
        $form = ['transaction_amount' => 100, 'token' => 'CARD-TOKEN-UNTOUCHED', 'payment_method_id' => 'visa', 'installments' => 1];
        if ($method === 'ticket') {
            $form['payment_method_id'] = 'bolbradesco';
        }
        $result = request('/process', $cookie, array_replace($payload, ['payment_method' => $method, 'form_data' => $form]), 'POST');
        check($result[0] === 200 && $result[2]['success'] === true, $method . ' valid token accepted');
        $calls = request('/trace', $cookie)[2];
        check(count($calls) === $index + 1, $method . ' exactly one simulated call');
        check(!isset($calls[$index]['payload']['csrf_token']), 'CSRF token never sent to payment API');
        if (str_ends_with($method, 'card')) {
            check($calls[$index]['payload']['token'] === 'CARD-TOKEN-UNTOUCHED', 'card token preserved');
        }
    }
    check($calls[0]['key'] === $calls[4]['key'], 'PIX retry preserves idempotency key');
    echo $passed . ' checks passed; fixture: ' . $root . PHP_EOL;
} finally {
    proc_terminate($server);
    fclose($pipes[0]);
    proc_close($server);
}
