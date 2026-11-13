import { useState, useRef, useEffect } from 'react';
import { ArrowUpRight, Package, ChevronDown, ChevronRight, Calculator } from 'lucide-react';
import { pieceWeightKg } from '../lib/products';
import type { Category, CalcOption } from '../lib/products';
import { ymGoal } from '../lib/ym';

type Props = {
  topCategories: Category[];
  subcategories: Record<string, Category[]>;
  products: Record<string, CalcOption[]>;
  loading: boolean;
  onProductSelect: (leafSlug: string, product: CalcOption) => void;
};

// ── Filter primitives ──────────────────────────────────────────────────────────

type RangeItem = { raw: string; from: number; to: number };
type FilterDef =
  | { type: 'range'; label: string; items: RangeItem[] }
  | { type: 'enum'; label: string; items: string[] };

function buildFilter(label: string | null | undefined, type: string | null | undefined, values: string | null | undefined): FilterDef | null {
  if (!label || !type || !values) return null;
  const parts = values.split(',').map((s) => s.trim()).filter(Boolean);
  if (parts.length === 0) return null;

  if (type === 'range') {
    const items: RangeItem[] = parts
      .map((s) => { const [a, b] = s.split('-').map(Number); return { raw: s, from: a, to: b }; })
      .filter((r) => !isNaN(r.from) && !isNaN(r.to));
    return items.length > 0 ? { type: 'range', label, items } : null;
  }

  if (type === 'enum') {
    return { type: 'enum', label, items: parts };
  }

  return null;
}

function matchesFilter(product: CalcOption, paramValue: string | null | undefined, filter: FilterDef, selected: string | null): boolean {
  if (selected === null) return true; // "Все" selected
  if (paramValue == null || paramValue === '') return false;

  if (filter.type === 'range') {
    const num = Number(paramValue);
    if (isNaN(num)) return false;
    const range = (filter.items as RangeItem[]).find((r) => r.raw === selected);
    return range != null && num >= range.from && num <= range.to;
  }

  // enum
  return paramValue === selected;
}

// ── Filter UI component ────────────────────────────────────────────────────────

function FilterBar({
  filter,
  selected,
  onSelect,
}: {
  filter: FilterDef;
  selected: string | null;
  onSelect: (v: string | null) => void;
}) {
  const labels: string[] =
    filter.type === 'range'
      ? (filter.items as RangeItem[]).map((r) => `${r.from}–${r.to}`)
      : (filter.items as string[]);

  const keys: string[] =
    filter.type === 'range'
      ? (filter.items as RangeItem[]).map((r) => r.raw)
      : (filter.items as string[]);

  return (
    <div className="flex flex-wrap items-center gap-2">
      <span className="mr-1 text-[11px] uppercase tracking-wider text-steel-500">
        {filter.label}
      </span>
      <button
        type="button"
        onClick={() => onSelect(null)}
        className={`rounded-md border px-3 py-1 text-xs font-semibold transition ${
          selected === null
            ? 'border-accent-500/70 bg-accent-500/15 text-accent-300'
            : 'border-white/10 bg-white/[0.03] text-steel-400 hover:border-white/20 hover:text-steel-200'
        }`}
      >
        Все
      </button>
      {keys.map((key, idx) => (
        <button
          key={key}
          type="button"
          onClick={() => onSelect(selected === key ? null : key)}
          className={`rounded-md border px-3 py-1 text-xs font-semibold transition ${
            selected === key
              ? 'border-accent-500/70 bg-accent-500/15 text-accent-300'
              : 'border-white/10 bg-white/[0.03] text-steel-400 hover:border-white/20 hover:text-steel-200'
          }`}
        >
          {labels[idx]}
        </button>
      ))}
    </div>
  );
}

// ── Main component ─────────────────────────────────────────────────────────────

export function Catalog({ topCategories, subcategories, products, loading, onProductSelect }: Props) {
  const [activeCat, setActiveCat] = useState<string | null>(null);
  const [activeSub, setActiveSub] = useState<string | null>(null);
  const [sel1, setSel1] = useState<string | null>(null);
  const [sel2, setSel2] = useState<string | null>(null);
  const panelRef = useRef<HTMLDivElement>(null);
  const subPickerRef = useRef<HTMLDivElement>(null);

  // После выбора категории прокручиваем так, чтобы выбор подкатегорий
  // оказался по центру экрана (если подкатегорий нет — сама панель).
  useEffect(() => {
    if (activeCat && panelRef.current) {
      requestAnimationFrame(() => {
        const target = subPickerRef.current ?? panelRef.current;
        target?.scrollIntoView({ behavior: 'smooth', block: 'center' });
      });
    }
  }, [activeCat]);

  function handleCatClick(slug: string) {
    if (activeCat === slug) {
      setActiveCat(null);
      setActiveSub(null);
    } else {
      setActiveCat(slug);
      setActiveSub(null);
      ymGoal('catalog_category_open');
    }
    setSel1(null);
    setSel2(null);
  }

  function handleSubClick(slug: string) {
    setActiveSub(activeSub === slug ? null : slug);
    setSel1(null);
    setSel2(null);
  }

  const activeCatData = topCategories.find((c) => c.slug === activeCat);
  const activeSubcats = activeCat ? (subcategories[activeCat] ?? []) : [];
  const isLeaf = activeCat !== null && activeSubcats.length === 0;

  const leafSlugForProducts = isLeaf ? activeCat! : (activeSub ?? '');
  const rawProducts: CalcOption[] =
    isLeaf ? (products[activeCat!] ?? []) : activeSub ? (products[activeSub] ?? []) : [];

  const activeSubData = activeSubcats.find((s) => s.slug === activeSub);
  const leafCategoryData: Category | undefined = isLeaf ? activeCatData : activeSubData;

  const filter1 = buildFilter(leafCategoryData?.filter1Label, leafCategoryData?.filter1Type, leafCategoryData?.filter1Values);
  const filter2 = buildFilter(leafCategoryData?.filter2Label, leafCategoryData?.filter2Type, leafCategoryData?.filter2Values);
  const hasFilters = rawProducts.length > 0 && (filter1 !== null || filter2 !== null);

  const displayedProducts = rawProducts.filter((p) => {
    const ok1 = filter1 ? matchesFilter(p, p.param1Value, filter1, sel1) : true;
    const ok2 = filter2 ? matchesFilter(p, p.param2Value, filter2, sel2) : true;
    return ok1 && ok2;
  });

  return (
    <section id="catalog" className="relative py-20 md:py-28">
      <div className="container-wide">

        {/* Header */}
        <div className="flex flex-col items-start justify-between gap-6 md:flex-row md:items-end">
          <div className="max-w-2xl">
            <span className="eyebrow">
              Каталог · {loading ? '...' : `${topCategories.length} направления`}
            </span>
            <h2 className="mt-5 font-display text-4xl font-semibold text-white md:text-5xl">
              Полный сортамент чёрного металлопроката
            </h2>
            <p className="mt-4 text-steel-300">
              Лист, трубы, сортовой прокат и арматура по ГОСТ и ТУ. Цены за тонну и за штуку по каждой позиции, резка в размер, доставка, сертификаты на каждую партию.
            </p>
          </div>
          <a
            href="#contact"
            className="hidden items-center gap-2 text-sm font-semibold text-accent-500 hover:text-accent-400 md:inline-flex"
          >
            Запросить прайс полностью
            <ArrowUpRight className="h-4 w-4" />
          </a>
        </div>

        {/* 4 Category cards */}
        <div className="mt-10 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
          {loading
            ? Array.from({ length: 4 }).map((_, i) => (
                <div
                  key={i}
                  className="h-80 animate-pulse rounded-2xl border border-white/[0.08] bg-graphite-900/60"
                />
              ))
            : topCategories.map((cat, i) => {
                const isActive = activeCat === cat.slug;
                return (
                  <button
                    key={cat.slug}
                    type="button"
                    onClick={() => handleCatClick(cat.slug)}
                    className={`group relative flex flex-col overflow-hidden rounded-2xl border bg-graphite-900/60 text-left transition hover:-translate-y-1 ${
                      isActive
                        ? 'border-accent-500/60 shadow-[0_0_24px_rgba(234,160,42,0.12)]'
                        : 'border-white/[0.08] hover:border-accent-500/40'
                    }`}
                    style={{ animationDelay: `${i * 60}ms` }}
                  >
                    <div className="relative aspect-[4/3] overflow-hidden">
                      <img
                        src={cat.image}
                        alt={cat.title}
                        className={`h-full w-full object-cover transition duration-700 group-hover:scale-105 ${
                          isActive ? 'grayscale-0' : 'grayscale group-hover:grayscale-0'
                        }`}
                      />
                      <div className="absolute inset-0 bg-gradient-to-t from-graphite-950 via-graphite-950/40 to-transparent" />
                      <div className="absolute left-4 top-4 chip backdrop-blur">
                        <Package className="h-3 w-3" />
                        {cat.inStock > 0 ? `${cat.inStock.toLocaleString('ru-RU')} т` : 'в наличии'}
                      </div>
                    </div>
                    <div className="flex flex-1 flex-col p-5">
                      <div className="flex items-start justify-between gap-2">
                        <h3
                          className={`font-display text-xl font-semibold transition ${
                            isActive ? 'text-accent-400' : 'text-white group-hover:text-accent-400'
                          }`}
                        >
                          {cat.title}
                        </h3>
                        {isActive ? (
                          <ChevronDown className="h-5 w-5 shrink-0 text-accent-500" />
                        ) : (
                          <ArrowUpRight className="h-5 w-5 shrink-0 text-steel-500 transition group-hover:rotate-12 group-hover:text-accent-500" />
                        )}
                      </div>
                      <p className="mt-1.5 text-sm text-steel-400">{cat.shortDesc}</p>
                      <div className="mt-5 border-t border-white/[0.06] pt-4">
                        {cat.priceFrom > 0 ? (
                          <div>
                            <div className="text-[10px] uppercase tracking-wider text-steel-500">Цена ₽/т от</div>
                            <div className="font-mono text-lg font-semibold text-white">
                              {cat.priceFrom.toLocaleString('ru-RU')}
                            </div>
                          </div>
                        ) : (
                          <span className="rounded-md bg-accent-500/10 px-2.5 py-1 text-[10px] font-semibold uppercase tracking-wider text-accent-500 ring-1 ring-accent-500/30">
                            {(subcategories[cat.slug] ?? []).length > 0
                              ? `${(subcategories[cat.slug] ?? []).length} подкатегории`
                              : 'в наличии'}
                          </span>
                        )}
                      </div>
                    </div>
                  </button>
                );
              })}
        </div>

        {/* Drill-down panel */}
        {activeCat && activeCatData && (
          <div ref={panelRef} className="mt-4 scroll-mt-4 overflow-hidden rounded-2xl border border-white/[0.08] bg-graphite-900/60 animate-fade-up">

            {/* Breadcrumb */}
            <div className="flex items-center gap-2 border-b border-white/[0.06] px-6 py-4">
              <button
                type="button"
                onClick={() => setActiveSub(null)}
                className={`text-sm font-medium transition ${
                  activeSub ? 'text-accent-400 hover:text-accent-300' : 'text-white'
                }`}
              >
                {activeCatData.title}
              </button>
              {activeSub && activeSubData && (
                <>
                  <ChevronRight className="h-4 w-4 text-steel-500" />
                  <span className="text-sm font-medium text-white">{activeSubData.title}</span>
                </>
              )}
            </div>

            <div className="p-6">
              {/* Subcategory pills */}
              {!isLeaf && (
                <div ref={subPickerRef} className="flex flex-wrap gap-2">
                  {activeSubcats.map((sub) => {
                    const hasProd = (products[sub.slug] ?? []).length > 0;
                    return (
                      <button
                        key={sub.slug}
                        type="button"
                        onClick={() => handleSubClick(sub.slug)}
                        className={`flex items-center gap-2 rounded-lg border px-4 py-2 text-sm font-medium transition ${
                          activeSub === sub.slug
                            ? 'border-accent-500 bg-accent-500/10 text-accent-300'
                            : 'border-white/10 bg-white/[0.02] text-steel-300 hover:border-white/25 hover:text-white'
                        }`}
                      >
                        {sub.title}
                        {hasProd && (
                          <span className="rounded bg-accent-500/20 px-1.5 py-0.5 text-[10px] font-semibold text-accent-400">
                            {products[sub.slug].length}
                          </span>
                        )}
                      </button>
                    );
                  })}
                </div>
              )}

              {/* Filter bars */}
              {hasFilters && (
                <div className={`space-y-3 ${!isLeaf ? 'mt-5' : ''}`}>
                  {filter1 && (
                    <FilterBar filter={filter1} selected={sel1} onSelect={setSel1} />
                  )}
                  {filter2 && (
                    <FilterBar filter={filter2} selected={sel2} onSelect={setSel2} />
                  )}
                </div>
              )}

              {/* Product list */}
              {displayedProducts.length > 0 && (
                <div className={!isLeaf || hasFilters ? 'mt-5' : ''}>

                  {/* Desktop table — hidden on mobile */}
                  <div className="hidden sm:block">
                    <div className="mb-2 grid grid-cols-[1fr_minmax(5.5rem,auto)_minmax(5.5rem,auto)_minmax(6.5rem,auto)_minmax(6rem,auto)] gap-x-4 px-4 text-[11px] uppercase tracking-wider text-steel-500">
                      <span>Наименование</span>
                      <span className="text-right">Цена за шт от</span>
                      <span className="text-right">Цена ₽/т от</span>
                      <span className="text-right">Остаток</span>
                      <span />
                    </div>
                    <div className="space-y-1">
                      {displayedProducts.map((p) => {
                        // У немерной вместо цены за штуку — «немерная 9–12 м»
                        const pieceKg = p.lengthKind === 'nemer' ? null : pieceWeightKg(p);
                        const pricePerPiece = pieceKg !== null ? Math.round(pieceKg * p.pricePerTon / 1000) : null;
                        const stock = p.inStock ?? 0;
                        return (
                          <div
                            key={p.id}
                            className="group/row grid grid-cols-[1fr_minmax(5.5rem,auto)_minmax(5.5rem,auto)_minmax(6.5rem,auto)_minmax(6rem,auto)] items-center gap-x-4 rounded-lg border border-white/[0.04] bg-white/[0.02] px-4 py-3 text-sm transition hover:border-accent-500/30 hover:bg-accent-500/[0.04]"
                          >
                            <span className="text-steel-200 transition group-hover/row:text-white">{p.label}</span>
                            {pricePerPiece !== null ? (
                              <span className="text-right font-mono text-steel-300">{pricePerPiece.toLocaleString('ru-RU')}</span>
                            ) : p.lengthText ? (
                              <span className="text-right text-xs leading-tight text-steel-400">{p.lengthText}</span>
                            ) : (
                              <span className="text-right text-steel-600">—</span>
                            )}
                            <span className="text-right font-mono font-semibold text-white">
                              {p.pricePerTon.toLocaleString('ru-RU')}
                            </span>
                            <span className="text-right text-xs">
                              {stock >= 1 ? (
                                <span className="font-semibold text-emerald-400">В наличии</span>
                              ) : (
                                <span className="text-steel-500">Под заказ</span>
                              )}
                            </span>
                            <button
                              type="button"
                              onClick={() => { ymGoal('quickcalc_open'); onProductSelect(leafSlugForProducts, p); }}
                              className="flex items-center gap-1.5 whitespace-nowrap rounded-md bg-accent-500/10 px-2.5 py-1.5 text-[11px] font-semibold text-accent-400 ring-1 ring-accent-500/30 transition hover:bg-accent-500/20 hover:text-accent-300"
                              title="Рассчитать стоимость"
                            >
                              <Calculator className="h-3 w-3" />
                              Рассчитать
                            </button>
                          </div>
                        );
                      })}
                    </div>
                  </div>

                  {/* Mobile cards — hidden on sm+ */}
                  <div className="space-y-2 sm:hidden">
                    {displayedProducts.map((p) => {
                      const pieceKg = p.lengthKind === 'nemer' ? null : pieceWeightKg(p);
                      const pricePerPiece = pieceKg !== null ? Math.round(pieceKg * p.pricePerTon / 1000) : null;
                      const stock = p.inStock ?? 0;
                      const params = [p.param1Value, p.param2Value].filter(Boolean).join(' · ');
                      return (
                        <div
                          key={p.id}
                          className="rounded-xl border border-white/[0.06] bg-white/[0.02] p-4"
                        >
                          <div className="text-sm font-semibold text-white">{p.label}</div>
                          {params && (
                            <div className="mt-0.5 text-[11px] text-steel-500">{params}</div>
                          )}
                          <div className="mt-3 flex items-end justify-between gap-3">
                            <div>
                              <div className="font-mono text-lg font-semibold text-white">
                                {p.pricePerTon.toLocaleString('ru-RU')} ₽/т
                              </div>
                              {(pricePerPiece !== null || p.lengthText) && (
                                <div className="text-[11px] text-steel-500">
                                  {pricePerPiece !== null ? `от ${pricePerPiece.toLocaleString('ru-RU')} ₽/шт` : p.lengthText}
                                </div>
                              )}
                            </div>
                            {stock >= 1 ? (
                              <span className="rounded-md bg-emerald-500/10 px-2 py-0.5 text-[11px] font-semibold text-emerald-400 ring-1 ring-emerald-500/30">
                                В наличии
                              </span>
                            ) : (
                              <span className="rounded-md bg-white/[0.04] px-2 py-0.5 text-[11px] text-steel-500 ring-1 ring-white/10">
                                Под заказ
                              </span>
                            )}
                          </div>
                          <button
                            type="button"
                            onClick={() => { ymGoal('quickcalc_open'); onProductSelect(leafSlugForProducts, p); }}
                            className="mt-3 flex w-full items-center justify-center gap-1.5 rounded-lg bg-accent-500/10 px-3 py-2.5 text-sm font-semibold text-accent-400 ring-1 ring-accent-500/30 transition active:bg-accent-500/20"
                          >
                            <Calculator className="h-4 w-4" />
                            Рассчитать
                          </button>
                        </div>
                      );
                    })}
                  </div>

                </div>
              )}

              {/* Empty state after active filter */}
              {displayedProducts.length === 0 && rawProducts.length > 0 && (
                <div className="mt-5 text-sm text-steel-500">
                  Нет позиций по выбранным фильтрам.
                </div>
              )}

              {/* Empty state — no products yet */}
              {rawProducts.length === 0 && (isLeaf || activeSub) && (
                <div className={`text-sm text-steel-500 ${!isLeaf ? 'mt-6' : ''}`}>
                  Наличие и цены по этому разделу уточняйте у менеджера — оставьте заявку.
                </div>
              )}

              {/* Hint */}
              {!isLeaf && !activeSub && activeSubcats.length > 0 && (
                <p className="mt-4 text-xs text-steel-600">
                  Выберите подкатегорию, чтобы увидеть список позиций
                </p>
              )}
            </div>
          </div>
        )}
      </div>
    </section>
  );
}
