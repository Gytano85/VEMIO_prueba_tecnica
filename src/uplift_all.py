"""Barrido de uplift para TODAS las promociones del histórico (soporte de la recomendación final)."""
import pandas as pd
from data_prep import load_raw, clean
from uplift import estimate_uplift

def run_all():
    df = clean(load_raw())
    combos = df.dropna(subset=['id_combo'])[['product_name', 'id_combo', 'combo']].drop_duplicates()
    rows = []
    for _, r in combos.iterrows():
        res, _, _ = estimate_uplift(df, r.product_name, r.id_combo, r.combo)
        rows.append(res)
    out = pd.DataFrame(rows).sort_values('margin_pct_of_incr_rev', ascending=False).reset_index(drop=True)
    out.to_csv("data/reto_c_all_combos.csv", index=False)
    return out

if __name__ == "__main__":
    out = run_all()
    pd.set_option('display.width', 200)
    print(out.to_string(index=False))
