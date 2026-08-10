"""Reto C: Uplift promocional — venta incremental y rentabilidad real de cada promoción.

Método: para cada combo se ajusta un modelo estacional (tendencia + mes sobre log(qty))
entrenado ÚNICAMENTE con semanas sin promoción de ese SKU, y se usa para predecir la
demanda contrafactual durante la ventana promocional. El uplift es la diferencia.

Sobre la métrica de rentabilidad
--------------------------------
El margen incremental se descompone analíticamente como:

    margen_incremental  =  I · (P − C)  −  A_promo · P · d
                           └─ganancia──┘   └──costo del descuento──┘

    I       = unidades incrementales (vs. contrafactual)
    A_promo = unidades vendidas dentro del combo
    P, C, d = precio de lista, costo unitario, profundidad de descuento

De ahí sale el umbral de decisión: la promo es rentable sólo si

    I / A_promo  >  P·d / (P − C)  =  (1+m)·d / m        [m = markup del SKU]

Esta es la métrica que se reporta ("uplift requerido" vs. "uplift observado"), porque es
comparable entre SKUs y traduce directamente a una decisión de aprobar o no una promo.

NOTA: se descartó deliberadamente una métrica de "margen incremental como % del ingreso
incremental". Cuando el descuento es profundo y el uplift pequeño, el ingreso incremental
puede ser negativo, y un margen negativo dividido entre un ingreso negativo da un porcentaje
POSITIVO — invirtiendo el ranking y poniendo las peores promociones arriba.
"""
import numpy as np
import pandas as pd
import statsmodels.api as sm
import sys

import matplotlib
# Agg solo cuando el modulo se ejecuta como script. Si pyplot ya esta cargado venimos
# del cuaderno, que ya fijo su backend con %matplotlib inline; cambiarlo aqui hacia que
# plt.show() dejara de dibujar y el cuaderno se publicaba sin una sola grafica.
if 'matplotlib.pyplot' not in sys.modules:
    matplotlib.use('Agg')
import matplotlib.pyplot as plt

from data_prep import load_raw, clean, weekly_demand, sku_economics, data_path, report_path

# Las dos promociones que se analizan en detalle (mejor y peor caso, ver run_all()).
PROMOS = [
    dict(sku='Antitranspirante 150 ml C', id_combo=20103.0, nombre='Combo Verano 2'),
    dict(sku='Desodorante 150 ml A', id_combo=20402.0, nombre='Combo Quincena Desodorante'),
]


def fit_baseline(wk):
    """Modelo estacional entrenado sólo con semanas SIN promoción -> demanda contrafactual."""
    wk = wk.copy()
    wk['month'] = wk.week.dt.month
    wk['t'] = np.arange(len(wk))
    train = wk[wk.promo_share < 0.01]

    Xtr = pd.get_dummies(train.month, prefix='m', drop_first=True).astype(float)
    Xtr.insert(0, 't', train.t.values)
    Xtr = sm.add_constant(Xtr)
    model = sm.OLS(np.log(train.qty.clip(lower=1)).astype(float), Xtr.astype(float)).fit()

    Xall = pd.get_dummies(wk.month, prefix='m', drop_first=True).astype(float)
    Xall.insert(0, 't', wk.t.values)
    Xall = sm.add_constant(Xall, has_constant='add').reindex(columns=Xtr.columns, fill_value=0.0)
    wk['baseline_qty'] = np.exp(model.predict(Xall))
    return wk, model


def estimate_uplift(df, sku, id_combo, nombre):
    promo_rows = df[(df.product_name == sku) & (df.id_combo == id_combo) & (~df.is_cancelled)]
    start, end = promo_rows.date.min(), promo_rows.date.max()

    # Economía anclada a la ventana de la promo: el costo unitario sube ~5-6%/año, así que usar
    # la mediana global del SKU sesgaría el margen de las promos de 2025 frente a las de 2026.
    econ = sku_economics(df, sku, start=start, end=end)
    P, C = econ['list_price'], econ['unit_cost']
    d = float(promo_rows.discount_imputed.mean())
    A_promo = int(promo_rows.sell_in_quantity.sum())

    wk, _ = fit_baseline(weekly_demand(df, sku))
    win = wk[(wk.week >= start - pd.Timedelta(days=6)) & (wk.week <= end)]

    actual = win.qty.sum()
    baseline = win.baseline_qty.sum()
    I = actual - baseline
    promo_share_window = A_promo / actual if actual else np.nan

    # margen real observado en la ventana vs. margen del contrafactual (todo a precio de lista)
    win_rows = df[(df.product_name == sku) & (df.date >= start) & (df.date <= end) & (~df.is_cancelled)]
    actual_revenue = win_rows.sell_in_amount.sum()
    actual_margin = actual_revenue - C * actual
    baseline_margin = baseline * (P - C)
    incr_margin = actual_margin - baseline_margin

    # umbral analítico de rentabilidad
    unit_margin = P - C
    discount_per_unit = P * d
    required_I = A_promo * discount_per_unit / unit_margin if unit_margin > 0 else np.inf
    sells_below_cost = P * (1 - d) < C
    if sells_below_cost:
        required_uplift_pct = np.inf
        ratio = 0.0
    else:
        required_uplift_pct = required_I / baseline * 100
        ratio = I / required_I if required_I > 0 else np.nan

    return dict(
        sku=sku, promo=nombre, inicio=start.date(), fin=end.date(), semanas=len(win),
        descuento=round(d, 3),
        descuento_equilibrio=round(econ['breakeven_discount'], 3),
        vende_bajo_costo=bool(sells_below_cost),
        unidades_promo=A_promo,
        unidades_reales=int(round(actual)),
        unidades_baseline=int(round(baseline)),
        unidades_incrementales=int(round(I)),
        uplift_obs_pct=round(I / baseline * 100, 1),
        uplift_req_pct=round(required_uplift_pct, 1) if np.isfinite(required_uplift_pct) else np.inf,
        cobertura=round(ratio, 2),                       # >1 => promo rentable
        margen_incremental=int(round(incr_margin)),
        ganancia_por_volumen=int(round(I * unit_margin)),
        costo_del_descuento=int(round(A_promo * discount_per_unit)),
        share_promo_en_ventana=round(promo_share_window, 3),
    ), wk, win


def run_all(df=None, save=True):
    df = clean(load_raw()) if df is None else df
    combos = df.dropna(subset=['id_combo'])[['product_name', 'id_combo', 'combo']].drop_duplicates()
    rows = [estimate_uplift(df, r.product_name, r.id_combo, r.combo)[0] for _, r in combos.iterrows()]
    out = pd.DataFrame(rows).sort_values('cobertura', ascending=False).reset_index(drop=True)
    if save:
        out.to_csv(data_path("reto_c_all_combos.csv"), index=False)
    return out


def plot_promos(df, promos=PROMOS, save=True):
    fig, axes = plt.subplots(len(promos), 1, figsize=(11, 8))
    results = []
    for ax, p in zip(np.atleast_1d(axes), promos):
        res, wk, win = estimate_uplift(df, p['sku'], p['id_combo'], p['nombre'])
        results.append(res)
        ax.plot(wk.week, wk.qty, color='steelblue', label='demanda real')
        ax.plot(wk.week, wk.baseline_qty, color='gray', ls='--', label='contrafactual (sin promo)')
        ax.axvspan(win.week.min(), win.week.max() + pd.Timedelta(days=6),
                   color='orange', alpha=0.2, label='ventana promocional')
        ax.set_title(f"{p['sku']} — {p['nombre']}   |   uplift observado {res['uplift_obs_pct']}%  vs  "
                     f"requerido {res['uplift_req_pct']}%   →   margen {res['margen_incremental']:+,}",
                     fontsize=10)
        ax.set_ylabel('unidades/semana')
        ax.legend(fontsize=8)
    plt.tight_layout()
    if save:
        plt.savefig(report_path("reto_c_uplift.png"), dpi=130)
    return pd.DataFrame(results)


if __name__ == "__main__":
    pd.set_option('display.width', 250)
    df = clean(load_raw())
    detail = plot_promos(df)
    detail.to_csv(data_path("reto_c_summary.csv"), index=False)
    print(detail.T.to_string())
    print("\n=== Las 19 promociones, ordenadas por cobertura (uplift obtenido / uplift necesario) ===")
    allc = run_all(df)
    print(allc[['sku', 'promo', 'descuento', 'vende_bajo_costo', 'uplift_obs_pct',
                'uplift_req_pct', 'cobertura', 'margen_incremental']].to_string(index=False))
