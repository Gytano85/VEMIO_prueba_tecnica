"""Reto B: Sensibilidad de la demanda al precio y simulador precio -> demanda / ingreso / margen.

ADVERTENCIA DE IDENTIFICACIÓN (importante para leer estos resultados):
en este dataset el precio efectivo semanal casi no varía por decisión de pricing "pura": varía
porque hay o no un combo activo (corr(log precio, promo_share) = -0.93). Por lo tanto el
coeficiente estimado NO es una elasticidad de precio de libro, sino el efecto combinado de
"activar un combo a profundidad d": precio + visibilidad + mecánica de bundle.
Esa es, de hecho, la palanca que el equipo comercial realmente controla — pero hay que
nombrarla por lo que es y no sobrevender la precisión del número.
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

ELASTICITY_SKU = 'Antitranspirante 150 ml C'


def price_panel(df, product_name):
    """Panel semanal de precio efectivo (ponderado por volumen) y cantidad."""
    d = df[(df.product_name == product_name) & (~df.is_cancelled) & (~df.is_gift)]
    g = weekly_demand(df, product_name).merge(
        d.groupby('week').agg(amount_ex_gift=('sell_in_amount', 'sum'),
                              qty_ex_gift=('sell_in_quantity', 'sum')).reset_index(),
        on='week', how='left')
    g['price'] = g.amount_ex_gift / g.qty_ex_gift
    g['t'] = np.arange(len(g))
    g['month'] = g.week.dt.month
    return g


def fit_specifications(panel):
    """Ajusta 3 especificaciones para verificar robustez del coeficiente de precio."""
    y = np.log(panel.qty).astype(float)
    M = pd.get_dummies(panel.month, prefix='m', drop_first=True).astype(float)
    log_price = pd.Series(np.log(panel.price), name='log_price')

    specs = {
        'base: log_price + tendencia + mes':
            pd.concat([log_price, panel[['t']], M], axis=1),
        'control por "hay combo activo"':
            pd.concat([log_price, panel[['on_promo', 't']], M], axis=1),
        'solo combo y profundidad (sin precio)':
            pd.concat([panel[['on_promo', 'discount', 't']], M], axis=1),
    }
    out = {}
    for name, X in specs.items():
        out[name] = sm.OLS(y, sm.add_constant(X.astype(float))).fit(cov_type='HC1')
    return out


def make_simulator(elasticity, ref_price, ref_qty, unit_cost, price_min, price_max):
    """Simulador de elasticidad constante anclado al nivel de negocio reciente.

    Devuelve demanda esperada, ingreso, margen ($ y %) y una bandera de si el precio
    consultado cae fuera del rango históricamente observado (donde no hay evidencia).
    """
    def simulate(price):
        price = np.atleast_1d(price).astype(float)
        qty = ref_qty * (price / ref_price) ** elasticity
        revenue = price * qty
        margin_abs = (price - unit_cost) * qty
        return pd.DataFrame({
            'price': price,
            'demanda_esperada': qty,
            'ingreso': revenue,
            'margen_abs': margin_abs,
            'margen_pct': np.where(revenue > 0, margin_abs / revenue, np.nan),
            'fuera_de_rango_observado': (price < price_min) | (price > price_max),
        })
    return simulate


def run(save=True):
    df = clean(load_raw())
    econ = sku_economics(df, ELASTICITY_SKU)
    panel = price_panel(df, ELASTICITY_SKU)
    specs = fit_specifications(panel)

    e_base = specs['base: log_price + tendencia + mes'].params['log_price']
    e_ctrl = specs['control por "hay combo activo"'].params['log_price']
    e_lo, e_hi = min(e_base, e_ctrl), max(e_base, e_ctrl)

    ref = panel.tail(12)
    ref_price, ref_qty = ref.price.mean(), ref.qty.mean()
    price_min, price_max = panel.price.min(), panel.price.max()

    sim = make_simulator(e_base, ref_price, ref_qty, econ['unit_cost'], price_min, price_max)
    grid = np.linspace(price_min, price_max, 60)
    res = sim(grid)
    # banda de robustez con el rango de elasticidades de las distintas especificaciones
    res['margen_abs_lo'] = make_simulator(e_lo, ref_price, ref_qty, econ['unit_cost'], price_min, price_max)(grid)['margen_abs']
    res['margen_abs_hi'] = make_simulator(e_hi, ref_price, ref_qty, econ['unit_cost'], price_min, price_max)(grid)['margen_abs']
    res['descuento_implicito'] = 1 - res.price / econ['list_price']

    if save:
        res.to_csv(data_path("reto_b_simulation_grid.csv"), index=False)

        fig, ax1 = plt.subplots(figsize=(9, 5.5))
        ax1.plot(res.price, res.ingreso, color='steelblue', label='Ingreso semanal')
        ax1.plot(res.price, res.margen_abs, color='seagreen', label='Margen $ semanal')
        ax1.fill_between(res.price, res.margen_abs_lo, res.margen_abs_hi, color='seagreen',
                         alpha=0.15, label=f'margen, rango elasticidad [{e_lo:.1f}, {e_hi:.1f}]')
        ax1.axhline(0, color='black', lw=0.8)
        ax1.axvline(econ['unit_cost'], color='crimson', ls='--', lw=1.2, label=f"costo unitario ({econ['unit_cost']:.1f})")
        ax1.axvline(ref_price, color='gray', ls=':', lw=1.2, label=f'precio actual ({ref_price:.1f})')
        ax1.set_xlabel('Precio unitario')
        ax1.set_ylabel('$ semanal')
        ax1.legend(fontsize=8, loc='upper center')
        ax1.set_title(f"Simulador de precio — {ELASTICITY_SKU}\n"
                      f"elasticidad estimada {e_base:.2f} · descuento de equilibrio {econ['breakeven_discount']:.1%}",
                      fontsize=11)
        plt.tight_layout()
        plt.savefig(report_path("reto_b_simulador.png"), dpi=130)

    return dict(econ=econ, panel=panel, specs=specs, sim=sim, grid=res,
                elasticity=e_base, elasticity_range=(e_lo, e_hi),
                ref_price=ref_price, ref_qty=ref_qty,
                price_min=price_min, price_max=price_max)


if __name__ == "__main__":
    r = run()
    pd.set_option('display.width', 200)
    print("Economía del SKU:", {k: round(v, 3) if isinstance(v, float) else v for k, v in r['econ'].items()})
    print(f"\nElasticidad estimada: {r['elasticity']:.2f}  (rango entre especificaciones: "
          f"{r['elasticity_range'][0]:.2f} a {r['elasticity_range'][1]:.2f})")
    for name, m in r['specs'].items():
        c = 'log_price' if 'log_price' in m.params else 'discount'
        print(f"   [{name}] {c} = {m.params[c]:.3f} (p={m.pvalues[c]:.3f}), R2={m.rsquared:.3f}")
    g = r['grid']
    print(f"\nPrecio que maximiza margen $ (dentro del rango observado): {g.loc[g.margen_abs.idxmax(),'price']:.2f}")
    print(f"Precio que maximiza ingreso $: {g.loc[g.ingreso.idxmax(),'price']:.2f}")
    print(f"Precio bajo el cual se vende a PÉRDIDA: {r['econ']['unit_cost']:.2f} "
          f"(descuento de {r['econ']['breakeven_discount']:.1%} sobre lista)")
