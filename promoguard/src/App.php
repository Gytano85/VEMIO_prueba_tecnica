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
