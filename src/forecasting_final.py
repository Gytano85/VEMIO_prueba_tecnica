"""Genera el forecast final (10 semanas) para cada SKU eligiendo el mejor modelo del backtest."""
import numpy as np, pandas as pd
import matplotlib
matplotlib.use('Agg')
import matplotlib.pyplot as plt
from data_prep import load_raw, clean, weekly_demand
from forecasting import (FORECAST_SKUS, HORIZON, build_features, fit_lgbm,
                          recursive_forecast_lgbm, seasonal_naive_forecast, backtest, wape)

df = clean(load_raw())

summary_rows = []
fig, axes = plt.subplots(len(FORECAST_SKUS), 1, figsize=(10, 12), sharex=False)

for i, sku in enumerate(FORECAST_SKUS):
    wk = weekly_demand(df, sku)
    n = len(wk)
    origins = list(range(n - HORIZON - 24, n - HORIZON + 1, 6))
    bt = backtest(wk, origins)
    wape_lgbm, wape_naive = bt.wape_lgbm.mean(), bt.wape_naive.mean()
    chosen = 'lgbm' if wape_lgbm < wape_naive else 'seasonal_naive'

    feat_full = build_features(wk)
    model = fit_lgbm(feat_full)
    fc_lgbm = recursive_forecast_lgbm(model, wk, HORIZON)
    fc_naive = seasonal_naive_forecast(wk, HORIZON)
    fc_final = fc_lgbm if chosen == 'lgbm' else fc_naive

    summary_rows.append({
        'sku': sku, 'wape_backtest_lgbm': round(wape_lgbm, 3), 'wape_backtest_naive': round(wape_naive, 3),
        'modelo_elegido': chosen, 'demanda_prom_semanal_hist': round(wk.qty.tail(12).mean(), 1),
        'demanda_prom_semanal_forecast': round(fc_final.yhat.mean(), 1)
    })

    fc_final.to_csv(f"data/forecast_{sku.replace(' ', '_').replace('/','-')}.csv", index=False)

    ax = axes[i]
    ax.plot(wk.week, wk.qty, label='histórico', color='steelblue')
    ax.plot(fc_lgbm.week, fc_lgbm.yhat, '--', label='forecast LGBM', color='darkorange')
    ax.plot(fc_naive.week, fc_naive.yhat, ':', label='forecast seasonal-naive', color='seagreen')
    ax.axvline(wk.week.iloc[-1], color='gray', lw=0.8)
    ax.set_title(f"{sku}  (modelo elegido: {chosen}, WAPE backtest={min(wape_lgbm,wape_naive):.1%})")
    ax.legend(fontsize=8)

plt.tight_layout()
plt.savefig("report/reto_a_forecasts.png", dpi=130)

summary = pd.DataFrame(summary_rows)
summary.to_csv("data/reto_a_summary.csv", index=False)
print(summary.to_string(index=False))
