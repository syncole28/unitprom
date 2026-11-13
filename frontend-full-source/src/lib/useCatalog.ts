import { useEffect, useState } from 'react';
import { fetchCatalog } from './api';
import { DEFAULT_SHEET_WIDTH_M, parseSheetSize } from './products';
import type { Category, CalcOption, LengthKind } from './products';

export type FeaturedItem = { id: string; label: string; pricePerTon: number };

type CatalogState = {
  topCategories: Category[];
  subcategories: Record<string, Category[]>;
  calcCatalog: Record<string, CalcOption[]>;
  leafCategories: Category[];
  subToParent: Record<string, string>;
  featured: FeaturedItem[];
  loading: boolean;
};

const TOP_LEVEL_SLUGS = ['listovoy', 'trubnyy', 'sortovoy', 'armatura'];
const SHEET_SLUG = 'listovoy';

export function useCatalog(): CatalogState {
  const [state, setState] = useState<CatalogState>({
    topCategories: [],
    subcategories: {},
    calcCatalog: {},
    leafCategories: [],
    subToParent: {},
    featured: [],
    loading: true,
  });

  useEffect(() => {
    let cancelled = false;

    async function load() {
      const { categories: catRows, products: prodRows, featured: featuredRows } = await fetchCatalog();

      if (cancelled) return;

      const allCategories: Category[] = (catRows ?? []).map((r: any) => ({
        slug: r.slug,
        title: r.title,
        shortDesc: r.short_desc,
        steel: r.steel,
        gostMain: r.gost_main,
        priceFrom: r.price_from,
        inStock: r.in_stock,
        image: r.image,
        parentSlug: r.parent_slug ?? null,
        filterLabel: r.filter_label ?? null,
        filterRanges: r.filter_ranges ?? null,
        filter1Label: r.filter1_label ?? null,
        filter1Type: r.filter1_type ?? null,
        filter1Values: r.filter1_values ?? null,
        filter2Label: r.filter2_label ?? null,
        filter2Type: r.filter2_type ?? null,
        filter2Values: r.filter2_values ?? null,
      }));

      const topCategories = allCategories.filter(
        (c) => c.parentSlug === null && TOP_LEVEL_SLUGS.includes(c.slug),
      );
      topCategories.sort(
        (a, b) => TOP_LEVEL_SLUGS.indexOf(a.slug) - TOP_LEVEL_SLUGS.indexOf(b.slug),
      );

      const subcategories: Record<string, Category[]> = {};
      const subToParent: Record<string, string> = {};
      for (const c of allCategories) {
        if (c.parentSlug) {
          if (!subcategories[c.parentSlug]) subcategories[c.parentSlug] = [];
          subcategories[c.parentSlug].push(c);
          subToParent[c.slug] = c.parentSlug;
        }
      }

      const calcCatalog: Record<string, CalcOption[]> = {};
      for (const r of (prodRows ?? []) as any[]) {
        if (!calcCatalog[r.category_slug]) calcCatalog[r.category_slug] = [];
        // Листы считаем по площади: размеры берём из названия (ширина по умолчанию 1,5 м).
        // Длину отдаёт сервер (из названия, вручную или по правилу); штуками продаётся только fixed.
        const isSheet = r.category_slug === SHEET_SLUG || subToParent[r.category_slug] === SHEET_SLUG;
        const lengthKind: LengthKind = r.length_kind ?? (r.length_m != null ? 'fixed' : 'none');
        let lengthM: number | null = lengthKind === 'fixed' && Number(r.length_m) > 0 ? Number(r.length_m) : null;
        // Немерную продаём штуками по расчётной длине (у листов так не считаем — там площадь)
        if (lengthKind === 'nemer' && !isSheet && Number(r.length_calc_m) > 0) lengthM = Number(r.length_calc_m);
        let sheetAreaM2: number | null = null;
        if (isSheet && lengthKind === 'fixed') {
          const size = parseSheetSize(String(r.label ?? ''));
          const widthM = size?.widthM ?? DEFAULT_SHEET_WIDTH_M;
          if (size) lengthM = size.lengthM;
          if (lengthM) sheetAreaM2 = Math.round(widthM * lengthM * 10000) / 10000;
        }
        calcCatalog[r.category_slug].push({
          id: r.id,
          label: r.label,
          weightPerMeter: Number(r.weight_per_meter),
          lengthM,
          lengthKind: lengthKind === 'nemer' ? 'nemer' : lengthM ? 'fixed' : lengthKind === 'fixed' ? 'none' : lengthKind,
          lengthText: r.length_text ?? '',
          isSheet,
          sheetAreaM2,
          pricePerTon: r.price_per_ton,
          paramValue: r.param_value != null ? Number(r.param_value) : null,
          inStock: r.in_stock ?? 0,
          param1Value: r.param1_value ?? null,
          param2Value: r.param2_value ?? null,
        });
      }

      const leafSlugs = new Set(Object.keys(calcCatalog));
      const leafCategories = allCategories.filter((c) => leafSlugs.has(c.slug));

      const featured: FeaturedItem[] = ((featuredRows ?? []) as any[]).map((r) => ({
        id: r.id,
        label: r.label,
        pricePerTon: Number(r.price_per_ton),
      }));

      setState({ topCategories, subcategories, calcCatalog, leafCategories, subToParent, featured, loading: false });
    }

    load();
    return () => { cancelled = true; };
  }, []);

  return state;
}
