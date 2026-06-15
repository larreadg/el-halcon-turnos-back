<?php

declare(strict_types=1);

class ApiResponse
{
    private function __construct(
        private readonly int    $code,
        private readonly string $status,
        private readonly string $message,
        private readonly mixed  $data = null,
    ) {}

    public static function success(string $message = 'OK', mixed $data = null, int $code = 200): self
    {
        return new self($code, 'success', $message, $data);
    }

    public static function error(string $message, int $code = 400): self
    {
        $status = match (true) {
            $code >= 500 => 'error',
            $code >= 400 => 'fail',
            default      => 'error',
        };

        return new self($code, $status, $message);
    }

    public function send(): void
    {
        http_response_code($this->code);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($this->toArray(), JSON_UNESCAPED_UNICODE);
        exit();
    }

    private function toArray(): array
    {
        return [
            'code'    => $this->code,
            'status'  => $this->status,
            'message' => $this->message,
            'data'    => $this->data,
        ];
    }
}
