"""Reto A: Demand forecasting semanal con backtesting walk-forward (sin fuga de información)."""
import numpy as np
import pandas as pd
import lightgbm as lgb

FORECAST_SKUS = ['Shampoo Rizos 135 ml', 'Desodorante 150 ml A', 'Cubito de pollo c/50']
HORIZON = 10  # semanas


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

FEATURES = ['t', 'woy_sin', 'woy_cos', 'month', 'lag_1', 'lag_2', 'lag_4', 'lag_8', 'lag_52',
            'roll_mean_4', 'roll_mean_8']


def fit_lgbm(train_feat):
    train = train_feat.dropna(subset=['lag_1'])  # necesita al menos lag_1 valido
    model = lgb.LGBMRegressor(
        n_estimators=200, num_leaves=7, min_child_samples=5,
        learning_rate=0.05, subsample=0.8, colsample_bytree=0.8,
        random_state=42, verbosity=-1)
    model.fit(train[FEATURES], train['qty'])
    return model


def recursive_forecast_lgbm(model, history_qty_df, n_steps):
    """history_qty_df: DataFrame con columnas week, qty (historia real, ordenada).
    Genera n_steps semanas futuras de forma recursiva usando las propias predicciones como lags."""
    hist = history_qty_df[['week', 'qty']].copy()
    freq = pd.tseries.frequencies.to_offset('W-MON')
    preds = []
    for h in range(n_steps):
        next_week = hist['week'].iloc[-1] + pd.Timedelta(days=7)
        tmp = pd.concat([hist, pd.DataFrame({'week': [next_week], 'qty': [np.nan]})], ignore_index=True)
        feat = build_features(tmp.rename(columns={'qty': 'qty'}))
        row = feat.iloc[[-1]][FEATURES]
        yhat = max(0, model.predict(row)[0])
        preds.append((next_week, yhat))
        hist = pd.concat([hist, pd.DataFrame({'week': [next_week], 'qty': [yhat]})], ignore_index=True)
    return pd.DataFrame(preds, columns=['week', 'yhat'])


def seasonal_naive_forecast(history_qty_df, n_steps):
    hist = history_qty_df[['week', 'qty']].copy().sort_values('week').reset_index(drop=True)
    preds = []
    for h in range(1, n_steps + 1):
        next_week = hist['week'].iloc[-1] + pd.Timedelta(days=7) * h
        ref_week = next_week - pd.Timedelta(weeks=52)
        match = hist.loc[hist.week == ref_week, 'qty']
        if len(match):
            yhat = match.values[0]
        else:
            yhat = hist['qty'].tail(4).mean()
        preds.append((next_week, yhat))
    return pd.DataFrame(preds, columns=['week', 'yhat'])


def wape(actual, pred):
    actual = np.asarray(actual, dtype=float)
    pred = np.asarray(pred, dtype=float)
    return np.abs(actual - pred).sum() / np.abs(actual).sum()


def backtest(weekly_df, origins, horizon=HORIZON):
    """origins: lista de índices (posición en la serie) donde 'corta' el histórico."""
    rows = []
    for origin in origins:
        train = weekly_df.iloc[:origin].reset_index(drop=True)
        test = weekly_df.iloc[origin:origin + horizon].reset_index(drop=True)
        if len(test) < horizon:
            continue
        feat_train = build_features(train)
        model = fit_lgbm(feat_train)
        fc_lgbm = recursive_forecast_lgbm(model, train, horizon)
        fc_naive = seasonal_naive_forecast(train, horizon)

        w_lgbm = wape(test['qty'], fc_lgbm['yhat'])
        w_naive = wape(test['qty'], fc_naive['yhat'])
        rows.append({'origin_week': train['week'].iloc[-1], 'wape_lgbm': w_lgbm, 'wape_naive': w_naive})
    return pd.DataFrame(rows)


if __name__ == "__main__":
    from data_prep import load_raw, clean, weekly_demand
    df = clean(load_raw())
    for sku in FORECAST_SKUS:
        wk = weekly_demand(df, sku)
        n = len(wk)
        origins = list(range(n - HORIZON - 24, n - HORIZON + 1, 6))
        bt = backtest(wk, origins)
        print(f"\n=== {sku} (n_weeks={n}) ===")
        print(bt)
        print("promedio WAPE LGBM:", bt.wape_lgbm.mean().round(3), " | seasonal naive:", bt.wape_naive.mean().round(3))
