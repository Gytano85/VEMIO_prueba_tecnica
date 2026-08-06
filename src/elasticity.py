"""Reto B: Elasticidad de precio y simulador de precio -> demanda/ingreso/margen."""
import numpy as np
import pandas as pd
import statsmodels.api as sm
import matplotlib
matplotlib.use('Agg')
import matplotlib.pyplot as plt

from data_prep import load_raw, clean

ELASTICITY_SKU = 'Antitranspirante 150 ml C'


def weekly_price_panel(df, product_name):
    d = df[(df.product_name == product_name) & (~df.is_cancelled) & (~df.is_gift)].copy()
    g = d.groupby('week').agg(
        qty=('sell_in_quantity', 'sum'),
        amount=('sell_in_amount', 'sum'),
        promo_qty=('sell_in_quantity', lambda s: s[d.loc[s.index, 'is_promo']].sum()),
    ).reset_index()
    g['price'] = g['amount'] / g['qty']  # precio efectivo ponderado por volumen
    g['promo_share'] = g['promo_qty'] / g['qty']
    g['month'] = g['week'].dt.month
    g['t'] = np.arange(len(g))
    g = g.sort_values('week').reset_index(drop=True)
    return g


def fit_elasticity(panel):
    X = pd.DataFrame({
        'log_price': np.log(panel['price']),
        't': panel['t'],
    })
    month_dum = pd.get_dummies(panel['month'], prefix='m', drop_first=True).astype(float)
    X = pd.concat([X, month_dum], axis=1)
    X = sm.add_constant(X)
    y = np.log(panel['qty'])
    model = sm.OLS(y.astype(float), X.astype(float)).fit(cov_type='HC1')
    return model


def make_simulator(elasticity, ref_price, ref_qty, unit_cost, price_min, price_max):
    def simulate(price):
        price = np.atleast_1d(price).astype(float)
        out_of_range = (price < price_min) | (price > price_max)
        qty = ref_qty * (price / ref_price) ** elasticity
        revenue = price * qty
        margin_abs = (price - unit_cost) * qty
        margin_pct = np.where(revenue > 0, margin_abs / revenue, np.nan)
        res = pd.DataFrame({
            'price': price, 'demanda_esperada': qty, 'ingreso': revenue,
            'margen_abs': margin_abs, 'margen_pct': margin_pct,
            'fuera_de_rango_observado': out_of_range
        })
        return res
    return simulate


if __name__ == "__main__":
    df = clean(load_raw())
    panel = weekly_price_panel(df, ELASTICITY_SKU)
    print("weeks:", len(panel), "price range:", panel.price.min(), panel.price.max())
    print(panel[['week','price','qty','promo_share']].head())

    model = fit_elasticity(panel)
    print(model.summary())

    elasticity = model.params['log_price']
    print("\nElasticidad precio-demanda estimada:", elasticity)

    unit_cost_recent = df[(df.product_name == ELASTICITY_SKU) & (df.sell_in_quantity > 0)].tail(2000)['unit_cost'].median()
    ref = panel.tail(12)
    ref_price = ref['price'].mean()
    ref_qty = ref['qty'].mean()
    price_min, price_max = panel.price.min(), panel.price.max()

    print(f"\nref_price={ref_price:.2f} ref_qty={ref_qty:.1f} unit_cost={unit_cost_recent:.2f}")
    print(f"rango observado: [{price_min:.2f}, {price_max:.2f}]")

    sim = make_simulator(elasticity, ref_price, ref_qty, unit_cost_recent, price_min, price_max)
    grid = np.linspace(price_min, price_max, 40)
    res = sim(grid)
    res.to_csv("data/reto_b_simulation_grid.csv", index=False)
    print(res.head())

    best_margin = res.loc[res.margen_abs.idxmax()]
    best_revenue = res.loc[res.ingreso.idxmax()]
    print("\nPrecio que maximiza margen $:\n", best_margin)
    print("\nPrecio que maximiza ingreso $:\n", best_revenue)

    fig, ax1 = plt.subplots(figsize=(8,5))
    ax1.plot(res.price, res.ingreso, color='steelblue', label='Ingreso')
    ax1.plot(res.price, res.margen_abs, color='seagreen', label='Margen $')
    ax1.axvline(ref_price, color='gray', ls='--', lw=1, label='precio actual (ref.)')
    ax1.set_xlabel('Precio unitario')
    ax1.set_ylabel('$ semanal')
    ax1.legend(loc='upper left')
    ax2 = ax1.twinx()
    ax2.plot(res.price, res.margen_pct*100, color='darkorange', ls=':', label='Margen %')
    ax2.set_ylabel('Margen %')
    plt.title(f"Simulador de precio - {ELASTICITY_SKU}  (elasticidad={elasticity:.2f})")
    fig.tight_layout()
    plt.savefig("report/reto_b_simulador.png", dpi=130)
