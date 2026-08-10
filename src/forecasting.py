"""Reto A: Forecasting de demanda semanal con validación walk-forward (sin fuga de información).

Tres modelos comparados:
  1. seasonal_naive  -- baseline: la demanda de t = la de t-52
  2. lgbm            -- calendario + lags + medias móviles
  3. lgbm_promo      -- lo anterior + el calendario promocional como regresor conocido a futuro

El calendario promocional NO es fuga de información: el equipo comercial define sus combos
por adelantado, así que en producción esas columnas se conocen para el horizonte proyectado.
"""
import numpy as np
import pandas as pd
import lightgbm as lgb

FORECAST_SKUS = ['Shampoo Rizos 135 ml', 'Desodorante 150 ml A', 'Cubito de pollo c/50']
HORIZON = 10  # semanas

BASE_FEATURES = ['t', 'woy_sin', 'woy_cos', 'month',
                 'lag_1', 'lag_2', 'lag_4', 'lag_8', 'lag_52',
                 'roll_mean_4', 'roll_mean_8']
PROMO_FEATURES = ['on_promo', 'discount']


def features_for(use_promo):
    return BASE_FEATURES + (PROMO_FEATURES if use_promo else [])


def build_features(g):
    g = g.sort_values('week').reset_index(drop=True).copy()
    g['t'] = np.arange(len(g))
    woy = g['week'].dt.isocalendar().week.astype(int)
    g['woy_sin'] = np.sin(2 * np.pi * woy / 52)
    g['woy_cos'] = np.cos(2 * np.pi * woy / 52)
    g['month'] = g['week'].dt.month
    for lag in [1, 2, 4, 8, 52]:
        g[f'lag_{lag}'] = g['qty'].shift(lag)
    g['roll_mean_4'] = g['qty'].shift(1).rolling(4).mean()
    g['roll_mean_8'] = g['qty'].shift(1).rolling(8).mean()
    return g


def fit_lgbm(train, use_promo):
    feat = build_features(train).dropna(subset=['lag_1'])
    # deterministic + force_row_wise + n_jobs=1 hacen el ajuste reproducible entre
    # ejecuciones y entornos. Sin esto LightGBM construye los histogramas en orden
    # dependiente del hilo y el WAPE varia ~0.5 pp entre corridas, lo que rompe la
    # trazabilidad entre el notebook y las cifras reportadas.
    model = lgb.LGBMRegressor(
        n_estimators=200, num_leaves=7, min_child_samples=5,
        learning_rate=0.05, colsample_bytree=0.8,
        random_state=42, verbosity=-1,
        deterministic=True, force_row_wise=True, n_jobs=1)
    model.fit(feat[features_for(use_promo)], feat['qty'])
    return model


def recursive_forecast(model, history, future_calendar, use_promo):
    """Pronóstico recursivo: cada semana futura usa las predicciones ya generadas como lags.

    history         -- DataFrame histórico con week, qty, on_promo, discount
    future_calendar -- DataFrame con week, on_promo, discount de las semanas a proyectar
                       (el plan promocional; ceros si se asume trimestre sin promoción)
    """
    cols = ['week', 'qty', 'on_promo', 'discount']
    hist = history[cols].copy()
    preds = []
    for _, fut in future_calendar.iterrows():
        row = pd.DataFrame({'week': [fut['week']], 'qty': [np.nan],
                            'on_promo': [fut['on_promo']], 'discount': [fut['discount']]})
        tmp = pd.concat([hist, row], ignore_index=True)
        x = build_features(tmp).iloc[[-1]][features_for(use_promo)]
        yhat = max(0.0, float(model.predict(x)[0]))
        preds.append((fut['week'], yhat))
        row.loc[0, 'qty'] = yhat
        hist = pd.concat([hist, row], ignore_index=True)
    return pd.DataFrame(preds, columns=['week', 'yhat'])


def seasonal_naive_forecast(history, n_steps):
    hist = history[['week', 'qty']].sort_values('week').reset_index(drop=True)
    preds = []
    for h in range(1, n_steps + 1):
        next_week = hist['week'].iloc[-1] + pd.Timedelta(days=7) * h
        match = hist.loc[hist.week == next_week - pd.Timedelta(weeks=52), 'qty']
        preds.append((next_week, match.values[0] if len(match) else hist['qty'].tail(4).mean()))
    return pd.DataFrame(preds, columns=['week', 'yhat'])


def wape(actual, pred):
    actual, pred = np.asarray(actual, float), np.asarray(pred, float)
    return np.abs(actual - pred).sum() / np.abs(actual).sum()


def backtest(weekly, origins, horizon=HORIZON):
    """Walk-forward: en cada origen se entrena SOLO con datos anteriores y se proyecta `horizon`."""
    rows = []
    for origin in origins:
        train = weekly.iloc[:origin].reset_index(drop=True)
        test = weekly.iloc[origin:origin + horizon].reset_index(drop=True)
        if len(test) < horizon:
            continue
        cal = test[['week', 'on_promo', 'discount']]
        cal_zero = cal.assign(on_promo=0, discount=0.0)

        rows.append({
            'origin_week': train['week'].iloc[-1],
            'wape_naive': wape(test['qty'], seasonal_naive_forecast(train, horizon)['yhat']),
            'wape_lgbm': wape(test['qty'],
                              recursive_forecast(fit_lgbm(train, False), train, cal_zero, False)['yhat']),
            'wape_lgbm_promo': wape(test['qty'],
                                    recursive_forecast(fit_lgbm(train, True), train, cal, True)['yhat']),
        })
    return pd.DataFrame(rows)


def backtest_origins(n_weeks, horizon=HORIZON, n_origins=5, step=6):
    return list(range(n_weeks - horizon - step * (n_origins - 1), n_weeks - horizon + 1, step))


if __name__ == "__main__":
    from data_prep import load_raw, clean, weekly_demand
    df = clean(load_raw())
    for sku in FORECAST_SKUS:
        wk = weekly_demand(df, sku)
        bt = backtest(wk, backtest_origins(len(wk)))
        print(f"\n=== {sku} (n={len(wk)} semanas) ===")
        print(bt.round(3).to_string(index=False))
        print("WAPE promedio -> " + "  |  ".join(
            f"{c.replace('wape_', '')}: {bt[c].mean():.1%}" for c in
            ['wape_naive', 'wape_lgbm', 'wape_lgbm_promo']))
