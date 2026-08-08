"""Genera el forecast final a 10 semanas bajo dos escenarios promocionales."""
import numpy as np
import pandas as pd
import matplotlib
matplotlib.use('Agg')
import matplotlib.pyplot as plt

from data_prep import load_raw, clean, weekly_demand, data_path, report_path
from forecasting import (FORECAST_SKUS, HORIZON, fit_lgbm, recursive_forecast,
                         seasonal_naive_forecast, backtest, backtest_origins)


def future_calendar(last_week, n_steps, on_promo=0, discount=0.0):
    weeks = [last_week + pd.Timedelta(days=7) * (h + 1) for h in range(n_steps)]
    return pd.DataFrame({'week': weeks, 'on_promo': on_promo, 'discount': discount})


def run():
    df = clean(load_raw())
    rows, forecasts = [], {}
    fig, axes = plt.subplots(len(FORECAST_SKUS), 1, figsize=(11, 12))

    for i, sku in enumerate(FORECAST_SKUS):
        wk = weekly_demand(df, sku)
        bt = backtest(wk, backtest_origins(len(wk)))

        # Modelo unificado para los 3 SKUs: LightGBM + calendario promocional.
        model = fit_lgbm(wk, use_promo=True)
        cal_base = future_calendar(wk.week.iloc[-1], HORIZON)                       # sin promo
        cal_promo = future_calendar(wk.week.iloc[-1], HORIZON, 1, 0.15)             # promo 15% todo el horizonte

        fc_base = recursive_forecast(model, wk, cal_base, True)
        fc_promo = recursive_forecast(model, wk, cal_promo, True)
        fc_naive = seasonal_naive_forecast(wk, HORIZON)

        forecasts[sku] = fc_base
        fc_base.to_csv(data_path(f"forecast_{sku.replace(' ', '_').replace('/', '-')}.csv"), index=False)

        rows.append({
            'sku': sku,
            'wape_naive': round(bt.wape_naive.mean(), 3),
            'wape_lgbm': round(bt.wape_lgbm.mean(), 3),
            'wape_lgbm_promo': round(bt.wape_lgbm_promo.mean(), 3),
            'demanda_sem_hist_12s': round(wk.qty.tail(12).mean(), 1),
            'forecast_sem_sin_promo': round(fc_base.yhat.mean(), 1),
            'forecast_sem_con_promo_15pct': round(fc_promo.yhat.mean(), 1),
        })

        ax = axes[i]
        ax.plot(wk.week.tail(60), wk.qty.tail(60), label='histórico', color='steelblue')
        ax.plot(fc_base.week, fc_base.yhat, '--', color='darkorange', label='forecast (sin promo)')
        ax.plot(fc_promo.week, fc_promo.yhat, '-.', color='crimson', label='forecast (promo 15%)')
        ax.plot(fc_naive.week, fc_naive.yhat, ':', color='seagreen', label='seasonal-naive (baseline)')
        ax.axvline(wk.week.iloc[-1], color='gray', lw=0.8)
        ax.set_title(f"{sku} — WAPE backtest LGBM+promo = {bt.wape_lgbm_promo.mean():.1%} "
                     f"(baseline naive {bt.wape_naive.mean():.1%})", fontsize=10)
        ax.set_ylabel('unidades/semana')
        ax.legend(fontsize=8)

    plt.tight_layout()
    plt.savefig(report_path("reto_a_forecasts.png"), dpi=130)

    summary = pd.DataFrame(rows)
    summary.to_csv(data_path("reto_a_summary.csv"), index=False)
    return summary


if __name__ == "__main__":
    s = run()
    pd.set_option('display.width', 200)
    print(s.to_string(index=False))
