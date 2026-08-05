import pandas as pd
import numpy as np
pd.set_option('display.width', 160)
pd.set_option('display.max_columns', 30)

path = "/sessions/gifted-zen-edison/mnt/Prueba Candidatos/20260806_prueba_tecnica_dataset.csv"
df = pd.read_csv(path, parse_dates=['date'], dayfirst=True)

# clean: drop cancelled tickets (qty==0), keep gift/sample (amount 0, qty>0) flagged
df['is_cancelled'] = df.sell_in_quantity==0
df['is_gift'] = (df.sell_in_amount==0) & (df.sell_in_quantity>0)
df['unit_price'] = np.where(df.sell_in_quantity>0, df.sell_in_amount/df.sell_in_quantity, np.nan)
df['unit_price_list'] = np.where(df.sell_in_quantity>0, df.bruto/df.sell_in_quantity, np.nan)

clean = df[~df.is_cancelled].copy()

print("=== Price variation per product (organic sales only, unit_price) ===")
organic = clean[clean.id_combo.isna() & ~clean.is_gift]
promo = clean[clean.id_combo.notna()]

for p, g in clean[~clean.is_gift].groupby('product_name'):
    print(p, "n=", len(g), "price min/median/max:", round(g.unit_price.min(),2), round(g.unit_price.median(),2), round(g.unit_price.max(),2), "cv:", round(g.unit_price.std()/g.unit_price.mean(),3))

print("\n=== Combos: date ranges, product, discount ===")
combo_summary = promo.groupby(['id_combo','combo','product_name']).agg(
    n=('sell_in_quantity','size'),
    qty=('sell_in_quantity','sum'),
    start=('date','min'),
    end=('date','max'),
    disc_mean=('discount','mean')
).reset_index().sort_values(['product_name','start'])
print(combo_summary.to_string())

