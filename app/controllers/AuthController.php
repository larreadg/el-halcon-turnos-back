<?php

declare(strict_types=1);

class AuthController
{
    public function captcha(): void
    {
        $captcha = new CaptchaService();
        ApiResponse::success('Captcha generado', $captcha->generarCaptcha($this->ip()))->send();
    }

    public function login(): void
    {
        $body      = $this->body();
        $usuario   = trim((string) ($body['usuario'] ?? ''));
        $clave     = (string) ($body['clave'] ?? '');
        $token     = trim((string) ($body['captcha_token'] ?? ''));
        $respuesta = trim((string) ($body['captcha_respuesta'] ?? ''));
        $ip        = $this->ip();

        if ($usuario === '' || $clave === '') {
            ApiResponse::error('Usuario y clave son requeridos', 400)->send();
            return;
        }

        if ($token === '' || $respuesta === '') {
            ApiResponse::error('Verificación requerida', 400)->send();
            return;
        }

        $captcha = new CaptchaService();

        if (!$captcha->verificarCaptcha($ip, $token, $respuesta)) {
            ApiResponse::error('Código de verificación incorrecto', 422)->send();
            return;
        }

        $service = new AuthService();
        $result  = $service->login($usuario, $clave);

        if (!$result) {
            ApiResponse::error('Credenciales inválidas', 401)->send();
            return;
        }

        ApiResponse::success('Login exitoso', $result)->send();
    }

    public function me(): void
    {
        $userId  = (int) Flight::get('user_id');
        $service = new AuthService();
        $user    = $service->findById($userId);

        if (!$user) {
            ApiResponse::error('Usuario no encontrado', 404)->send();
            return;
        }

        ApiResponse::success('Usuario obtenido', $user)->send();
    }

    private function body(): array
    {
        $raw  = Flight::request()->body;
        $data = json_decode($raw, true);
        return is_array($data) ? $data : [];
    }

    private function ip(): string
    {
        return $_SERVER['HTTP_X_FORWARDED_FOR']
            ?? $_SERVER['HTTP_X_REAL_IP']
            ?? $_SERVER['REMOTE_ADDR']
            ?? '0.0.0.0';
    }
}
