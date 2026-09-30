<?php

declare(strict_types=1);

namespace WHMCS\Module\Gateway\SeixastecMercadoPago;

// No networking exists in this mock. The real Api.php is never copied.
final class Api
{
    public function __construct(string $token)
    {
    }
    public function createPayment(array $payload, string $key): array
    {
        $file = dirname(__DIR__, 3) . '/calls.json';
        $calls = is_file($file) ? json_decode(file_get_contents($file), true) : [];
        $calls[] = ['payload' => $payload, 'key' => $key];
        file_put_contents($file, json_encode($calls));
        return ['id' => '123', 'status' => $payload['payment_method_id'] === 'visa' ? 'in_process' : 'pending'];
    }
    public function getLastError(): ?string
    {
        return null;
    }
}
