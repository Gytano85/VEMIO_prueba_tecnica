"""Carga y limpieza compartida del dataset VEMIO."""
import os
import pandas as pd
import numpy as np

# Rutas ancladas a la raíz del proyecto: los scripts corren desde cualquier directorio.
PROJECT_ROOT = os.path.abspath(os.path.join(os.path.dirname(__file__), ".."))
DATA_DIR = os.path.join(PROJECT_ROOT, "data")
REPORT_DIR = os.path.join(PROJECT_ROOT, "report")


def data_path(*parts):
    os.makedirs(DATA_DIR, exist_ok=True)
    return os.path.join(DATA_DIR, *parts)


def report_path(*parts):
    os.makedirs(REPORT_DIR, exist_ok=True)
    return os.path.join(REPORT_DIR, *parts)


# El dataset crudo lo provee VEMIO por fuera del repo (no se versiona: es data de cliente).
# Colocar el CSV en data/raw/ antes de ejecutar, o setear la variable de entorno VEMIO_DATASET_PATH.
RAW_PATH = os.environ.get(
    "VEMIO_DATASET_PATH",
    os.path.join(DATA_DIR, "raw", "20260806_prueba_tecnica_dataset.csv"),
)


def load_raw(path=RAW_PATH):
    return pd.read_csv(path, parse_dates=['date'], dayfirst=True)


def clean(df):
    df = df.copy()

    # --- 1 fila con metadata de producto totalmente vacía: se recupera por product_code ---
    meta_cols = ['category', 'subcategory', 'brand', 'basket']
    lookup = (df.dropna(subset=meta_cols)
                .drop_duplicates('product_code')[['product_code'] + meta_cols]
                .set_index('product_code'))
    mask = df[meta_cols].isna().all(axis=1)
    for c in meta_cols:
        df.loc[mask, c] = df.loc[mask, 'product_code'].map(lookup[c])

    # --- flags de calidad de dato (no se elimina ninguna fila: se marca y se filtra por análisis) ---
    df['is_cancelled'] = df.sell_in_quantity == 0                          # ticket cancelado: no es demanda
    df['is_gift'] = (df.sell_in_amount == 0) & (df.sell_in_quantity > 0)   # muestra/regalo: demanda sí, precio no
    df['is_promo'] = df.id_combo.notna()

    # --- descuento: nulo != 0 ---
    # Orgánica sin descuento registrado -> 0. En combo sin descuento registrado -> mediana del mismo combo,
    # porque el descuento se define a nivel de combo y no siempre se prorratea línea por línea.
    df['discount_imputed'] = df['discount']
    df.loc[~df.is_promo & df.discount.isna(), 'discount_imputed'] = 0.0
    promo_null = df.is_promo & df.discount.isna()
    if promo_null.any():
        combo_median = df.groupby('id_combo')['discount'].median()
        df.loc[promo_null, 'discount_imputed'] = df.loc[promo_null, 'id_combo'].map(combo_median)

    # --- unitarios (solo definidos con qty > 0) ---
    df['unit_price'] = np.where(df.sell_in_quantity > 0, df.sell_in_amount / df.sell_in_quantity, np.nan)
    df['unit_list_price'] = np.where(df.sell_in_quantity > 0, df.bruto / df.sell_in_quantity, np.nan)
    df['unit_cost'] = np.where(df.sell_in_quantity > 0, df.product_cost / df.sell_in_quantity, np.nan)

    # --- bruto / product_cost nulos: se reconstruyen con la relación fija por SKU
    #     precio_lista = costo * (1 + margen), verificada empíricamente en el notebook ---
    sku_list_price = df.dropna(subset=['unit_list_price']).groupby('product_code')['unit_list_price'].median()
    sku_cost = df.dropna(subset=['unit_cost']).groupby('product_code')['unit_cost'].median()

    bruto_na = df.bruto.isna() & (df.sell_in_quantity > 0)
    df.loc[bruto_na, 'unit_list_price'] = df.loc[bruto_na, 'product_code'].map(sku_list_price)
    df.loc[bruto_na, 'bruto'] = df.loc[bruto_na, 'unit_list_price'] * df.loc[bruto_na, 'sell_in_quantity']

    cost_na = df.product_cost.isna() & (df.sell_in_quantity > 0)
    df.loc[cost_na, 'unit_cost'] = df.loc[cost_na, 'product_code'].map(sku_cost)
    df.loc[cost_na, 'product_cost'] = df.loc[cost_na, 'unit_cost'] * df.loc[cost_na, 'sell_in_quantity']

    df['week'] = df['date'].dt.to_period('W-SUN').dt.start_time
    return df


def weekly_demand(df, product_name, exclude_cancelled=True):
    """Serie semanal de demanda de un SKU, con el calendario promocional de esa semana.

    `on_promo` y `discount` son variables CONOCIDAS A FUTURO: el equipo comercial define
    su calendario de combos por adelantado, así que usarlas como regresor de forecasting
    no constituye fuga de información.
    """
    d = df[df.product_name == product_name]
    if exclude_cancelled:
        d = d[~d.is_cancelled]

    g = d.groupby('week').agg(
        qty=('sell_in_quantity', 'sum'),
        amount=('sell_in_amount', 'sum'),
        n_tickets=('ticket_code', 'nunique'),
    ).reset_index()

    promo = (d[d.is_promo].groupby('week')
               .agg(promo_qty=('sell_in_quantity', 'sum'),
                    discount=('discount_imputed', 'mean'))
               .reset_index())
    g = g.merge(promo, on='week', how='left')
    g['promo_qty'] = g['promo_qty'].fillna(0)
    g['discount'] = g['discount'].fillna(0.0)
    g['promo_share'] = (g.promo_qty / g.qty).fillna(0)
    g['on_promo'] = (g.discount > 0).astype(int)
    return g.sort_values('week').reset_index(drop=True)


def sku_economics(df, product_name, start=None, end=None):
    """Costo unitario, markup, precio de lista y descuento de equilibrio de un SKU.

    `product_margin` es un MARKUP sobre costo, no un margen sobre ingreso. El margen sobre
    ingreso es m/(1+m), y ese es el descuento máximo que se puede dar sin vender bajo costo.

    El costo unitario NO es constante en el tiempo: sube ~5-6% al año en este dataset. Por eso
    `start`/`end` permiten anclar la economía a la ventana analizada; usar la mediana global del
    SKU sesga la comparación entre promociones de distintos años.
    """
    g = df[(df.product_name == product_name) & (df.sell_in_quantity > 0)]
    if start is not None:
        g = g[g.date >= start]
    if end is not None:
        g = g[g.date <= end]
    m = g.product_margin.iloc[0]
    cost = g.unit_cost.median()
    return dict(
        product_name=product_name,
        markup=m,
        unit_cost=cost,
        list_price=cost * (1 + m),
        margin_on_revenue=m / (1 + m),
        breakeven_discount=m / (1 + m),
    )


if __name__ == "__main__":
    df = clean(load_raw())
    print("shape:", df.shape)
    print(df[['discount_imputed', 'bruto', 'product_cost']].isna().sum())
    print(pd.DataFrame([sku_economics(df, p) for p in df.product_name.unique()]).round(3))
