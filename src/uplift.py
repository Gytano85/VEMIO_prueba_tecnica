"""Reto C: Uplift promocional -- venta incremental vs. contrafactual (baseline sin promo)."""
import numpy as np
import pandas as pd
import statsmodels.api as sm
import matplotlib
matplotlib.use('Agg')
import matplotlib.pyplot as plt

from data_prep import load_raw, clean, weekly_demand

PROMOS = [
    dict(sku='Antitranspirante 150 ml C', id_combo=20103.0, nombre='Combo Verano 2'),
    dict(sku='Cubito de pollo c/50', id_combo=20004.0, nombre='Combo Invierno 2'),
]


def fit_baseline_model(wk):
    """Ajusta un modelo estacional (tendencia + mes) SOLO con semanas sin promoción,
    y lo usa para predecir la demanda contrafactual (sin promo) en todas las semanas."""
    wk = wk.copy()
    wk['month'] = wk['week'].dt.month
    wk['t'] = np.arange(len(wk))
    train = wk[wk.promo_share < 0.01]

    X_train = pd.get_dummies(train['month'], prefix='m', drop_first=True).astype(float)
    X_train.insert(0, 't', train['t'].values)
    X_train = sm.add_constant(X_train)
    y_train = np.log(train['qty'].clip(lower=1))
    model = sm.OLS(y_train.astype(float), X_train.astype(float)).fit()

    X_all = pd.get_dummies(wk['month'], prefix='m', drop_first=True).astype(float)
    X_all.insert(0, 't', wk['t'].values)
    X_all = sm.add_constant(X_all, has_constant='add')
    X_all = X_all[X_train.columns]  # mismo orden/columnas
    wk['baseline_qty'] = np.exp(model.predict(X_all))
    return wk, model


def promo_window(df, sku, id_combo):
    d = df[(df.product_name == sku) & (df.id_combo == id_combo)]
    return d.date.min(), d.date.max()


def estimate_uplift(df, sku, id_combo, nombre):
    wk = weekly_demand(df, sku)
    wk, model = fit_baseline_model(wk)

    start, end = promo_window(df, sku, id_combo)
    promo_weeks = wk[(wk.week >= start - pd.Timedelta(days=6)) & (wk.week <= end)]

    actual_units = promo_weeks.qty.sum()
    baseline_units = promo_weeks.baseline_qty.sum()
    incr_units = actual_units - baseline_units

    # precios: real (transacciones del periodo) vs. precio de referencia no-promo (list/organic reciente)
    d_period = df[(df.product_name == sku) & (df.date >= start) & (df.date <= end) & (~df.is_cancelled)]
    actual_revenue = d_period.sell_in_amount.sum()
    unit_cost = df[(df.product_name == sku) & (df.sell_in_quantity > 0)].unit_cost.median()

    ref_price = df[(df.product_name == sku) & (~df.is_promo) & (~df.is_cancelled) & (~df.is_gift)
                    & (df.date < start)].tail(3000).unit_price.median()

    baseline_revenue = baseline_units * ref_price
    incr_revenue = actual_revenue - baseline_revenue

    actual_margin = actual_revenue - unit_cost * actual_units
    baseline_margin = baseline_revenue - unit_cost * baseline_units
    incr_margin = actual_margin - baseline_margin

    return dict(
        sku=sku, promo=nombre, inicio=start.date(), fin=end.date(),
        semanas=len(promo_weeks),
        actual_units=round(actual_units), baseline_units=round(baseline_units),
        incremental_units=round(incr_units), uplift_pct=round(incr_units / baseline_units * 100, 1),
        ref_price=round(ref_price, 2), actual_revenue=round(actual_revenue),
        incremental_revenue=round(incr_revenue), incremental_margin=round(incr_margin),
        margin_pct_of_incr_rev=round(incr_margin / incr_revenue * 100, 1) if incr_revenue else np.nan
    ), wk, promo_weeks


if __name__ == "__main__":
    df = clean(load_raw())
    results = []
    fig, axes = plt.subplots(len(PROMOS), 1, figsize=(10, 8))
    for i, p in enumerate(PROMOS):
        res, wk, promo_weeks = estimate_uplift(df, p['sku'], p['id_combo'], p['nombre'])
        results.append(res)
        print(res)

        ax = axes[i]
        ax.plot(wk.week, wk.qty, label='demanda real', color='steelblue')
        ax.plot(wk.week, wk.baseline_qty, label='baseline (contrafactual sin promo)', color='gray', ls='--')
        ax.axvspan(promo_weeks.week.min(), promo_weeks.week.max() + pd.Timedelta(days=6),
                   color='orange', alpha=0.2, label='ventana promo')
        ax.set_title(f"{p['sku']} — {p['nombre']}")
        ax.legend(fontsize=8)

    plt.tight_layout()
    plt.savefig("report/reto_c_uplift.png", dpi=130)

    out = pd.DataFrame(results)
    out.to_csv("data/reto_c_summary.csv", index=False)
    print("\n", out.to_string(index=False))
