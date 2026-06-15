<?php

declare(strict_types=1);

class CaptchaService
{
    private const CAPTCHA_EXPIRY = 300; // segundos (5 min)
    private const CAPTCHA_LENGTH = 6;

    private PDO $db;

    public function __construct()
    {
        $this->db = Flight::get('db');
    }

    public function generarCaptcha(string $ip): array
    {
        $valor  = $this->generarCodigo();
        $token  = bin2hex(random_bytes(16));
        $expira = date('Y-m-d H:i:s', time() + self::CAPTCHA_EXPIRY);

        $this->db->prepare(
            "INSERT INTO login_intento (ip, captcha_token, captcha_valor, captcha_expira)
             VALUES (?, ?, ?, ?)
             ON CONFLICT(ip) DO UPDATE SET
                 captcha_token  = excluded.captcha_token,
                 captcha_valor  = excluded.captcha_valor,
                 captcha_expira = excluded.captcha_expira"
        )->execute([$ip, $token, $valor, $expira]);

        return [
            'captcha_token'  => $token,
            'captcha_imagen' => $this->generarImagen($valor),
        ];
    }

    public function verificarCaptcha(string $ip, string $token, string $respuesta): bool
    {
        $stmt = $this->db->prepare(
            "SELECT captcha_valor, captcha_expira
             FROM login_intento
             WHERE ip = ? AND captcha_token = ?"
        );
        $stmt->execute([$ip, $token]);
        $row = $stmt->fetch();

        if (!$row) {
            return false;
        }

        // Single-use: invalidar el captcha antes de evaluar
        $this->limpiarCaptcha($ip);

        if (strtotime($row['captcha_expira']) < time()) {
            return false;
        }

        return strtolower(trim($respuesta)) === $row['captcha_valor'];
    }

    private function limpiarCaptcha(string $ip): void
    {
        $this->db->prepare('DELETE FROM login_intento WHERE ip = ?')
                 ->execute([$ip]);
    }

    private function generarCodigo(): string
    {
        $chars  = 'abcdefghijklmnopqrstuvwxyz0123456789';
        $result = '';
        for ($i = 0; $i < self::CAPTCHA_LENGTH; $i++) {
            $result .= $chars[random_int(0, strlen($chars) - 1)];
        }
        return $result;
    }

    private function generarImagen(string $codigo): string
    {
        $lineas = '';
        for ($i = 0; $i < 5; $i++) {
            $x1 = random_int(0, 200);
            $y1 = random_int(0, 70);
            $x2 = random_int(0, 200);
            $y2 = random_int(0, 70);
            $r  = random_int(160, 200);
            $g  = random_int(160, 200);
            $b  = random_int(200, 230);
            $lineas .= "<line x1=\"$x1\" y1=\"$y1\" x2=\"$x2\" y2=\"$y2\" stroke=\"rgb($r,$g,$b)\" stroke-width=\"1.5\"/>";
        }

        $puntos = '';
        for ($i = 0; $i < 40; $i++) {
            $cx = random_int(0, 200);
            $cy = random_int(0, 70);
            $r  = random_int(160, 200);
            $g  = random_int(160, 200);
            $b  = random_int(200, 230);
            $puntos .= "<circle cx=\"$cx\" cy=\"$cy\" r=\"1\" fill=\"rgb($r,$g,$b)\"/>";
        }

        $texto = '';
        $x = 10;
        foreach (str_split($codigo) as $char) {
            $r      = random_int(40, 100);
            $g      = random_int(40, 120);
            $b      = random_int(130, 200);
            $size   = random_int(22, 28);
            $rotate = random_int(-15, 15);
            $y      = random_int(42, 52);
            $cx     = $x + 12;
            $texto .= "<text x=\"$cx\" y=\"$y\" font-size=\"$size\" font-family=\"monospace\" font-weight=\"bold\" fill=\"rgb($r,$g,$b)\" transform=\"rotate($rotate,$cx,$y)\">$char</text>";
            $x += random_int(26, 34);
        }

        $svg = "<svg xmlns=\"http://www.w3.org/2000/svg\" width=\"200\" height=\"70\" viewBox=\"0 0 200 70\"><rect width=\"200\" height=\"70\" fill=\"#f5f5fa\" rx=\"6\"/>$lineas$puntos$texto</svg>";

        return 'data:image/svg+xml;base64,' . base64_encode($svg);
    }
}
