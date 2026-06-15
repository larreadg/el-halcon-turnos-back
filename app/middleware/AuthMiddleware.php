<?php

declare(strict_types=1);

class AuthMiddleware
{
    public function before(array $_params): bool
    {
        $authHeader = $this->getAuthorizationHeader();

        if ($authHeader === '' || !str_starts_with($authHeader, 'Bearer ')) {
            ApiResponse::error('Token no proporcionado', 401)->send();
        }

        $token = substr($authHeader, 7);
        $payload = JwtHelper::verify($token);

        if (!$payload) {
            ApiResponse::error('Token inválido o expirado', 401)->send();
        }

        Flight::set('user_id', (int) $payload['sub']);
        Flight::set('user_rol', $payload['rol'] ?? null);

        return true;
    }
    
    private function getAuthorizationHeader(): string
    {
        $header = Flight::request()->getHeader('Authorization');

        if ($header !== '') {
            return $header;
        }

        if (!empty($_SERVER['HTTP_AUTHORIZATION'])) {
            return (string) $_SERVER['HTTP_AUTHORIZATION'];
        }

        if (!empty($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
            return (string) $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
        }

        if (function_exists('getallheaders')) {
            foreach (getallheaders() as $key => $value) {
                if (strcasecmp($key, 'Authorization') === 0) {
                    return (string) $value;
                }
            }
        }

        return '';
    }
}