<?php
declare(strict_types=1);

namespace PromoGuard;

use PDO;

/** Definición del esquema SQLite del sistema. */
final class Schema
{
    public const DDL = <<<'SQL'
DROP TABLE IF EXISTS skus;
CREATE TABLE skus (
    product_code        INTEGER PRIMARY KEY,
    product_name        TEXT    NOT NULL,
    category            TEXT,
    subcategory         TEXT,
    brand               TEXT,
    basket              TEXT,
    markup              REAL    NOT NULL,   -- product_margin: markup sobre costo
    unit_cost           REAL    NOT NULL,   -- costo unitario reciente
    list_price          REAL    NOT NULL,   -- unit_cost * (1 + markup)
    margin_on_revenue   REAL    NOT NULL,   -- markup / (1 + markup)
    breakeven_discount  REAL    NOT NULL,   -- = margin_on_revenue
    elasticity          REAL,               -- sensibilidad estimada (log-log)
    elasticity_r2       REAL,
    baseline_weekly     REAL,               -- demanda semanal sin promocion
    total_units         INTEGER,
    total_revenue       REAL,
    price_min           REAL,
    price_max           REAL
);

DROP TABLE IF EXISTS weekly_demand;
CREATE TABLE weekly_demand (
    product_code INTEGER NOT NULL,
    week         TEXT    NOT NULL,
    units        INTEGER NOT NULL,
    revenue      REAL    NOT NULL,
    avg_price    REAL,
    on_promo     INTEGER NOT NULL DEFAULT 0,
    discount     REAL    NOT NULL DEFAULT 0,
    baseline     REAL,
    PRIMARY KEY (product_code, week)
);

DROP TABLE IF EXISTS promotions;
CREATE TABLE promotions (
    id_combo           INTEGER PRIMARY KEY,
    combo              TEXT    NOT NULL,
    product_code       INTEGER NOT NULL,
    start_date         TEXT    NOT NULL,
    end_date           TEXT    NOT NULL,
    weeks              INTEGER NOT NULL,
    discount           REAL    NOT NULL,
    breakeven_discount REAL    NOT NULL,
    sells_below_cost   INTEGER NOT NULL DEFAULT 0,
    promo_units        INTEGER NOT NULL,
    actual_units       INTEGER NOT NULL,
    baseline_units     INTEGER NOT NULL,
    incremental_units  INTEGER NOT NULL,
    uplift_obs_pct     REAL    NOT NULL,
    uplift_req_pct     REAL,                -- NULL cuando vende bajo costo (inalcanzable)
    coverage           REAL    NOT NULL,
    revenue            REAL    NOT NULL,
    volume_gain        REAL    NOT NULL,
    discount_cost      REAL    NOT NULL,
    incremental_margin REAL    NOT NULL
);

DROP TABLE IF EXISTS forecasts;
CREATE TABLE forecasts (
    product_code INTEGER NOT NULL,
    week         TEXT    NOT NULL,
    scenario     TEXT    NOT NULL,
    units        REAL    NOT NULL,
    PRIMARY KEY (product_code, week, scenario)
);

DROP TABLE IF EXISTS meta;
CREATE TABLE meta (
    key   TEXT PRIMARY KEY,
    value TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS simulations (
    id             INTEGER PRIMARY KEY AUTOINCREMENT,
    created_at     TEXT    NOT NULL,
    product_code   INTEGER NOT NULL,
    product_name   TEXT    NOT NULL,
    discount       REAL    NOT NULL,
    weeks          INTEGER NOT NULL,
    verdict        TEXT    NOT NULL,
    incremental_margin REAL NOT NULL,
    coverage       REAL    NOT NULL,
    payload        TEXT    NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_weekly_sku  ON weekly_demand(product_code);
CREATE INDEX IF NOT EXISTS idx_promo_sku   ON promotions(product_code);
CREATE INDEX IF NOT EXISTS idx_sim_created ON simulations(created_at DESC);
SQL;

    public static function apply(PDO $pdo): void
    {
        $pdo->exec(self::DDL);
    }
}
