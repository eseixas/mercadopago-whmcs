<?php

declare(strict_types=1);

namespace WHMCS\Database {
    final class Capsule
    {
        public static function table(string $table): Query
        {
            return new Query($table);
        }
    }
    final class Query
    {
        private array $filters = [];
        public function __construct(private string $table)
        {
        }
        public function where(string $column, mixed $value): self
        {
            $this->filters[$column] = $value;
            return $this;
        }
        public function join(...$args): self
        {
            return $this;
        }
        public function whereIn(...$args): self
        {
            return $this;
        }
        public function value(...$args): mixed
        {
            return null;
        }
        public function first(): ?object
        {
            if ($this->table === 'tblinvoices') {
                $id = $this->filters['id'] ?? 0;
                $owner = $id === 2 ? 202 : 101;
                if (!in_array($id, [1, 2, 3, 4], true) || ($this->filters['userid'] ?? null) !== $owner) {
                    return null;
                }
                return (object) ['id' => $id, 'userid' => $owner, 'total' => 100,
                    'status' => $id === 3 ? 'Paid' : ($id === 4 ? 'Cancelled' : 'Unpaid')];
            }
            if ($this->table === 'tblclients') {
                return (object) ['email' => 'mock@example.invalid', 'firstname' => 'Mock', 'lastname' => 'Client',
                    'address1' => 'Rua A, 100', 'postcode' => '30110000', 'address2' => 'Centro', 'city' => 'Belo Horizonte', 'state' => 'MG'];
            }
            throw new \RuntimeException('Unexpected mock DB query: ' . $this->table);
        }
    }
}

namespace {
    function getGatewayVariables(string $name): array
    {
        return ['type' => 'gateway', 'accessToken' => 'MOCK-NEVER-A-CREDENTIAL', 'publicKey' => 'MOCK',
            'systemurl' => 'https://billing.example.invalid/whmcs', 'companyname' => 'Mock', 'feePercent' => 0];
    }
    function logModuleCall(...$args): void
    {
    }
    function logModule(...$args): void
    {
    }
}
