import pandas as pd
import numpy as np

pd.set_option('display.width', 160)
pd.set_option('display.max_columns', 30)

path = "/sessions/gifted-zen-edison/mnt/Prueba Candidatos/20260806_prueba_tecnica_dataset.csv"
df = pd.read_csv(path, parse_dates=['date'], dayfirst=True)

print("shape:", df.shape)
print(df.dtypes)
print("\ndate range:", df.date.min(), df.date.max())
print("\nproducts:\n", df.product_name.value_counts())
print("\nwarehouses:", df.warehouse.nunique())
print("clients:", df.client_code.nunique())
print("combos:", df.id_combo.nunique())

print("\nnulls:\n", df.isna().sum())

print("\nqty==0 rows:", (df.sell_in_quantity==0).sum())
print("amount==0 & qty>0 rows:", ((df.sell_in_amount==0) & (df.sell_in_quantity>0)).sum())
print("qty<0 rows:", (df.sell_in_quantity<0).sum())
print("amount<0 rows:", (df.sell_in_amount<0).sum())

# incomplete metadata row
meta_cols = ['category','subcategory','brand','basket']
incomplete = df[df[meta_cols].isna().all(axis=1)]
print("\nrows with all metadata cols null:", len(incomplete))
print(incomplete.head())

print("\ndiscount stats:\n", df.discount.describe())
print("\npromo share (id_combo not null):", df.id_combo.notna().mean())

