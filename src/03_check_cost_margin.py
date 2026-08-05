import pandas as pd, numpy as np
path = "/sessions/gifted-zen-edison/mnt/Prueba Candidatos/20260806_prueba_tecnica_dataset.csv"
df = pd.read_csv(path, parse_dates=['date'], dayfirst=True)
df = df[df.sell_in_quantity>0].copy()
df['unit_cost'] = df.product_cost/df.sell_in_quantity
df['unit_list_price'] = df.bruto/df.sell_in_quantity

for p, g in df.groupby('product_name'):
    uc = g.unit_cost.dropna()
    margin = g.product_margin.dropna().unique()
    implied_list = uc.mean()*(1+margin[0]) if len(margin) else np.nan
    actual_list = g.unit_list_price.dropna().mean()
    print(f"{p:28s} unit_cost mean={uc.mean():.4f} std={uc.std():.5f}  margin={margin}  implied_list={implied_list:.3f} actual_list_mean={actual_list:.3f}")
