<?php
declare(strict_types=1);

namespace PromoGuard;

use PDO;

/** Acceso a datos. Todas las consultas del sistema viven aquí. */
final class Repository
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public static function connect(string $path): PDO
    {
        $pdo = new PDO('sqlite:' . $path);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec('PRAGMA journal_mode = WAL');
        return $pdo;
    }

    public function isReady(): bool
    {
        try {
            $r = $this->pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='skus'");
            return $r !== false && $r->fetch() !== false;
        } catch (\PDOException $e) {
            return false;
        }
    }

    /** @return array<int,array<string,mixed>> */
    public function skus(): array
    {
        return $this->pdo->query('SELECT * FROM skus ORDER BY total_revenue DESC')->fetchAll();
    }

    public function sku(int $code): ?array
    {
        $st = $this->pdo->prepare('SELECT * FROM skus WHERE product_code = ?');
        $st->execute([$code]);
        $row = $st->fetch();
        return $row === false ? null : $row;
    }

    public function firstSku(): ?array
    {
        $row = $this->pdo->query('SELECT * FROM skus ORDER BY total_revenue DESC LIMIT 1')->fetch();
        return $row === false ? null : $row;
    }

    /** @return array<int,array<string,mixed>> */
    public function promotions(?int $productCode = null): array
    {
        $sql = 'SELECT p.*, s.product_name FROM promotions p
                JOIN skus s ON s.product_code = p.product_code';
        $args = [];
        if ($productCode !== null) {
            $sql .= ' WHERE p.product_code = ?';
            $args[] = $productCode;
        }
        $sql .= ' ORDER BY p.coverage DESC';
        $st = $this->pdo->prepare($sql);
        $st->execute($args);
        return $st->fetchAll();
    }

    public function promotion(int $idCombo): ?array
    {
        $st = $this->pdo->prepare(
            'SELECT p.*, s.product_name, s.markup, s.unit_cost, s.list_price, s.elasticity, s.baseline_weekly
             FROM promotions p JOIN skus s ON s.product_code = p.product_code
             WHERE p.id_combo = ?'
        );
        $st->execute([$idCombo]);
        $row = $st->fetch();
        return $row === false ? null : $row;
    }

    /** @return array<int,array<string,mixed>> */
    public function weekly(int $productCode): array
    {
        $st = $this->pdo->prepare('SELECT * FROM weekly_demand WHERE product_code = ? ORDER BY week');
        $st->execute([$productCode]);
        return $st->fetchAll();
    }

    /** @return array<int,array<string,mixed>> */
    public function forecast(int $productCode): array
    {
        $st = $this->pdo->prepare('SELECT * FROM forecasts WHERE product_code = ? ORDER BY week, scenario');
        $st->execute([$productCode]);
        return $st->fetchAll();
    }

    /** Indicadores de portada. @return array<string,mixed> */
    public function headline(): array
    {
        $r = $this->pdo->query(
            'SELECT COUNT(*) AS total,
                    SUM(CASE WHEN incremental_margin > 0 THEN 1 ELSE 0 END) AS profitable,
                    SUM(CASE WHEN sells_below_cost = 1 THEN 1 ELSE 0 END)   AS below_cost,
                    SUM(incremental_margin)  AS margin_total,
                    SUM(discount_cost)       AS discount_total,
                    SUM(volume_gain)         AS volume_total,
                    SUM(incremental_units)   AS incremental_units,
                    SUM(promo_units)         AS promo_units,
                    SUM(revenue)             AS revenue,
                    MAX(coverage)            AS best_coverage
             FROM promotions'
        )->fetch();

        $skus = $this->pdo->query('SELECT COUNT(*) AS n FROM skus')->fetch();
        $weeks = $this->pdo->query('SELECT COUNT(DISTINCT week) AS n FROM weekly_demand')->fetch();

        return array_merge($r ?: [], [
            'sku_count'  => (int) ($skus['n'] ?? 0),
            'week_count' => (int) ($weeks['n'] ?? 0),
        ]);
    }

    /** @return array<string,string> */
    public function meta(): array
    {
        $out = [];
        foreach ($this->pdo->query('SELECT key, value FROM meta')->fetchAll() as $r) {
            $out[$r['key']] = $r['value'];
        }
        return $out;
    }

    public function saveSimulation(array $sku, array $sim): int
    {
        $st = $this->pdo->prepare(
            'INSERT INTO simulations (created_at, product_code, product_name, discount, weeks,
                verdict, incremental_margin, coverage, payload)
             VALUES (?,?,?,?,?,?,?,?,?)'
        );
        $st->execute([
            date('c'), $sku['product_code'], $sku['product_name'], $sim['discount'], $sim['weeks'],
            $sim['verdict'], $sim['incremental_margin'], $sim['coverage'],
            json_encode($sim, JSON_UNESCAPED_UNICODE),
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    public function deleteSimulation(int $id): void
    {
        $st = $this->pdo->prepare('DELETE FROM simulations WHERE id = ?');
        $st->execute([$id]);
    }

    /** @return array<int,array<string,mixed>> */
    public function simulations(int $limit = 20): array
    {
        $st = $this->pdo->prepare('SELECT * FROM simulations ORDER BY created_at DESC LIMIT ?');
        $st->bindValue(1, $limit, PDO::PARAM_INT);
        $st->execute();
        return $st->fetchAll();
    }
}
