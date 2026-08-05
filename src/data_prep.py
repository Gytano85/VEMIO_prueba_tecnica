"""Carga y limpieza compartida del dataset VEMIO."""
import pandas as pd
import numpy as np

import os

# El dataset crudo lo provee VEMIO por fuera del repo (no se versiona: es data de cliente).
# Colocar el CSV en data/raw/ antes de ejecutar, o setear la variable de entorno VEMIO_DATASET_PATH.
RAW_PATH = os.environ.get(
    "VEMIO_DATASET_PATH",
    os.path.join(os.path.dirname(__file__), "..", "data", "raw", "20260806_prueba_tecnica_dataset.csv"),
)

def load_raw(path=RAW_PATH):
    df = pd.read_csv(path, parse_dates=['date'], dayfirst=True)
    return df

def clean(df):
    df = df.copy()
    # 1 fila con metadata de producto totalmente vacía -> se puede recuperar por product_code
    meta_cols = ['category', 'subcategory', 'brand', 'basket']
    lookup = (df.dropna(subset=meta_cols)
                .drop_duplicates('product_code')[['product_code'] + meta_cols]
                .set_index('product_code'))
    mask = df[meta_cols].isna().all(axis=1)
    for c in meta_cols:
        df.loc[mask, c] = df.loc[mask, 'product_code'].map(lookup[c])

    # flags de calidad de dato
    df['is_cancelled'] = df.sell_in_quantity == 0          # ticket cancelado -> no representa demanda
    df['is_gift'] = (df.sell_in_amount == 0) & (df.sell_in_quantity > 0)  # muestra/regalo -> demanda real, ingreso 0
    df['is_promo'] = df.id_combo.notna()

    # descuento: nulo != 0. Nulo = "no se pudo calcular a nivel de línea" (se prorratea a nivel combo).
    # Para ventas orgánicas (sin combo) un nulo se asume 0 (no hubo descuento). Para ventas en combo con
    # discount nulo, se imputa la mediana de descuento del mismo combo (mismo id_combo).
    df['discount_imputed'] = df['discount']
    organic_null = df.is_promo.eq(False) & df.discount.isna()
    df.loc[organic_null, 'discount_imputed'] = 0.0
    promo_null = df.is_promo & df.discount.isna()
    if promo_null.any():
        combo_median = df.groupby('id_combo')['discount'].median()
        df.loc[promo_null, 'discount_imputed'] = df.loc[promo_null, 'id_combo'].map(combo_median)

    # unit price / cost -- solo definidos cuando qty>0
    df['unit_price'] = np.where(df.sell_in_quantity > 0, df.sell_in_amount / df.sell_in_quantity, np.nan)
    df['unit_list_price'] = np.where(df.sell_in_quantity > 0, df.bruto / df.sell_in_quantity, np.nan)
    df['unit_cost'] = np.where(df.sell_in_quantity > 0, df.product_cost / df.sell_in_quantity, np.nan)

    # bruto/product_cost nulos (fuera de la fila de metadata incompleta) -> imputar con precio de lista teórico
    # implícito por costo y margen fijo del SKU (list = cost*(1+margin); cost = list/(1+margin))
    sku_margin = df.groupby('product_code')['product_margin'].first()
    sku_list_price = (df.dropna(subset=['unit_list_price'])
                         .groupby('product_code')['unit_list_price'].median())
    sku_cost = (df.dropna(subset=['unit_cost'])
                  .groupby('product_code')['unit_cost'].median())

    bruto_na = df.bruto.isna() & (df.sell_in_quantity > 0)
    df.loc[bruto_na, 'unit_list_price'] = df.loc[bruto_na, 'product_code'].map(sku_list_price)
    df.loc[bruto_na, 'bruto'] = df.loc[bruto_na, 'unit_list_price'] * df.loc[bruto_na, 'sell_in_quantity']

    cost_na = df.product_cost.isna() & (df.sell_in_quantity > 0)
    df.loc[cost_na, 'unit_cost'] = df.loc[cost_na, 'product_code'].map(sku_cost)
    df.loc[cost_na, 'product_cost'] = df.loc[cost_na, 'unit_cost'] * df.loc[cost_na, 'sell_in_quantity']

    df['week'] = df['date'].dt.to_period('W-SUN').dt.start_time
    return df

def weekly_demand(df, product_name, exclude_cancelled=True):
    d = df[df.product_name == product_name]
    if exclude_cancelled:
        d = d[~d.is_cancelled]
    g = d.groupby('week').agg(
        qty=('sell_in_quantity', 'sum'),
        amount=('sell_in_amount', 'sum'),
        n_tickets=('ticket_code', 'nunique'),
        promo_qty=('sell_in_quantity', lambda s: s[d.loc[s.index, 'is_promo']].sum()),
    ).reset_index()
    g['promo_share'] = (g.promo_qty / g.qty).fillna(0)
    return g.sort_values('week').reset_index(drop=True)

if __name__ == "__main__":
    raw = load_raw()
    df = clean(raw)
    print("clean shape", df.shape)
    print(df[['discount', 'discount_imputed']].isna().sum())
    print(df[['bruto', 'product_cost']].isna().sum())
    wk = weekly_demand(df, 'Desodorante 150 ml A')
    print(wk.head())
    print(wk.tail())
    print("n weeks:", len(wk))
