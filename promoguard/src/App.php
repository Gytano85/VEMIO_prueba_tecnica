<?php
declare(strict_types=1);

namespace PromoGuard;

/** Contenedor mínimo: configuración, conexión, servicios y render de vistas. */
final class App
{
    public array $config;
    public Repository $repo;
    public Advisor $advisor;

    public function __construct(array $config)
    {
        $this->config = $config;
        $pdo = Repository::connect($config['database']);
        $this->repo = new Repository($pdo);
        $this->advisor = new Advisor($config['ai'] ?? []);
    }

    /** Token anti-CSRF para las rutas que escriben. */
    public static function csrfToken(): string
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start(['cookie_httponly' => true, 'cookie_samesite' => 'Lax']);
        }
        if (empty($_SESSION['csrf'])) {
            $_SESSION['csrf'] = bin2hex(random_bytes(16));
        }
        return $_SESSION['csrf'];
    }

    public static function csrfValid(?string $token): bool
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start(['cookie_httponly' => true, 'cookie_samesite' => 'Lax']);
        }
        return is_string($token) && !empty($_SESSION['csrf'])
            && hash_equals((string) $_SESSION['csrf'], $token);
    }

    public static function boot(): self
    {
        self::registerAutoloader();
        $config = require dirname(__DIR__) . '/config.php';
        return new self($config);
    }

    public static function registerAutoloader(): void
    {
        static $registered = false;
        if ($registered) {
            return;
        }
        spl_autoload_register(static function (string $class): void {
            $prefix = 'PromoGuard\\';
            if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
                return;
            }
            $file = __DIR__ . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            if (is_file($file)) {
                require_once $file;
            }
        });
        $registered = true;
    }

    /** Renderiza una vista dentro del layout. */
    public function render(string $view, array $data = []): void
    {
        $data['app'] = $this;
        $viewFile = dirname(__DIR__) . '/views/' . $view . '.php';
        if (!is_file($viewFile)) {
            http_response_code(404);
            echo 'Vista no encontrada: ' . htmlspecialchars($view, ENT_QUOTES);
            return;
        }

        extract($data, EXTR_SKIP);
        ob_start();
        require $viewFile;
        $content = ob_get_clean();

        require dirname(__DIR__) . '/views/layout.php';
    }

    /** Página de error legible: una excepción de PDO no debe dejar la pantalla en blanco. */
    public static function fail(string $message, int $status = 500): void
    {
        http_response_code($status);
        header('Content-Type: text/html; charset=utf-8');
        $m = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');
        echo '<!doctype html><meta charset="utf-8"><title>PromoGuard</title>'
           . '<style>body{font:15px/1.6 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;'
           . 'background:#FBFBFC;color:#10192B;margin:0;display:grid;place-items:center;min-height:100vh;padding:24px}'
           . 'div{max-width:520px}h1{font-size:19px;margin:0 0 8px}p{color:#566072;margin:0 0 14px}'
           . 'code{background:#F6F7F9;border-radius:4px;padding:2px 6px;font-size:13px}</style>'
           . '<div><h1>No se pudo abrir el sistema</h1><p>' . $m . '</p>'
           . '<p>Si es la primera vez que lo levantas, corre <code>php bin/import.php ruta/al.csv</code>.</p></div>';
    }

    public function json(array $payload, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    }

    public function url(string $route, array $params = []): string
    {
        $q = array_merge(['r' => $route], $params);
        return '?' . http_build_query($q);
    }

    // ------------------------------------------------------------- formateo

    public static function e(?string $v): string
    {
        return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
    }

    public static function money(float $v, int $dec = 0): string
    {
        $sign = $v < 0 ? '−' : '';
        return $sign . '$' . number_format(abs($v), $dec, '.', ',');
    }

    public static function compact(float $v): string
    {
        $sign = $v < 0 ? '−' : '';
        $a = abs($v);
        if ($a >= 1_000_000) {
            return $sign . '$' . number_format($a / 1_000_000, 2) . 'M';
        }
        if ($a >= 1_000) {
            return $sign . '$' . number_format($a / 1_000, 1) . 'k';
        }
        return $sign . '$' . number_format($a, 0);
    }

    public static function pct(?float $v, int $dec = 1): string
    {
        if ($v === null) {
            return '—';
        }
        return number_format($v * 100, $dec) . '%';
    }

    public static function num(float $v, int $dec = 0): string
    {
        return number_format($v, $dec, '.', ',');
    }
}
