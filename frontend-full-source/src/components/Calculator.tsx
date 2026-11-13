import { useMemo, useState } from 'react';
import { Calculator as CalcIcon, ArrowRight, CheckCircle2, ChevronRight, ChevronDown } from 'lucide-react';
import { pieceWeightKg } from '../lib/products';
import type { Category, CalcOption } from '../lib/products';
import { ymGoal, ymEcommerceAdd } from '../lib/ym';
import { itemQtyText, itemEcommerce } from '../lib/cartFormat';

type Result = {
  weightKg: number;
  totalPrice: number;
  pricePerTon: number;
  productLabel: string;
  qty: number;
  lengthM: number;
  areaM2?: number;
  amount?: number;
  amountUnit?: 'м' | 'м²';
  nemer?: boolean;
};

type Props = {
  topCategories: Category[];
  subcategories: Record<string, Category[]>;
  calcCatalog: Record<string, CalcOption[]>;
  loading: boolean;
  onAddToCart: (payload: Result) => void;
  onOrder: (payload: Result) => void;
};

function capitalize(s: string): string {
  return s ? s[0].toUpperCase() + s.slice(1) : s;
}

export function Calculator({ topCategories, subcategories, calcCatalog, loading, onAddToCart, onOrder }: Props) {
  const [topSlug, setTopSlug] = useState<string | null>(null);
  const [subSlug, setSubSlug] = useState<string | null>(null);
  const [productId, setProductId] = useState<string>('');
  const [qtyStr, setQtyStr] = useState<string>('20');
  const [result, setResult] = useState<Result | null>(null);

  const qty = Math.max(1, parseInt(qtyStr, 10) || 0);
  // Немерные, в бухте и без длины считаем в метрах (у листа — в м²), а не штуками
  const amountM = Math.max(0, parseFloat(qtyStr.replace(',', '.')) || 0);

  const availableSubcats = topSlug ? (subcategories[topSlug] ?? []) : [];
  const isLeaf = topSlug !== null && availableSubcats.length === 0;
  const leafSlug = subSlug ?? (isLeaf ? topSlug : null);
  const productOptions = leafSlug ? (calcCatalog[leafSlug] ?? []) : [];

  const product = useMemo(
    () => productOptions.find((p) => p.id === productId) ?? productOptions[0],
    [productOptions, productId],
  );

  function handleTopCatClick(slug: string) {
    if (topSlug === slug) {
      setTopSlug(null);
      setSubSlug(null);
      setProductId('');
    } else {
      setTopSlug(slug);
      setSubSlug(null);
      setProductId('');
    }
    setResult(null);
  }

  function handleSubClick(slug: string) {
    setSubSlug(slug);
    setProductId('');
    setResult(null);
  }

  const pieceKg = product ? pieceWeightKg(product) : null;
  const byPiece = pieceKg !== null;
  const isNemer = byPiece && product?.lengthKind === 'nemer';
  const amountUnit: 'м' | 'м²' = product?.isSheet ? 'м²' : 'м';

  function handleCalc() {
    if (!product) return;
    if (pieceKg === null) {
      if (!(amountM > 0)) return;
      const weightKg = product.weightPerMeter * amountM;
      const totalPrice = (weightKg / 1000) * product.pricePerTon;
      setResult({ weightKg, totalPrice, pricePerTon: product.pricePerTon, productLabel: product.label, qty: 0, lengthM: 0, amount: Math.round(amountM * 1000) / 1000, amountUnit });
      ymGoal('calculator_use');
      return;
    }
    const lengthM = product.lengthM ?? 0;
    const weightKg = pieceKg * qty;
    const totalPrice = (weightKg / 1000) * product.pricePerTon;
    const areaM2 = product.sheetAreaM2 ?? undefined;
    setResult({ weightKg, totalPrice, pricePerTon: product.pricePerTon, productLabel: product.label, qty, lengthM, ...(areaM2 ? { areaM2 } : {}), ...(isNemer ? { nemer: true } : {}) });
    ymGoal('calculator_use');
  }

  const topCatData = topCategories.find((c) => c.slug === topSlug);
  const subCatData = availableSubcats.find((c) => c.slug === subSlug);

  function trackAdd() {
    if (!result || !product) return;
    ymGoal('cart_add');
    ymEcommerceAdd({
      id: product.id,
      name: product.label,
      ...itemEcommerce(result),
    });
  }

  function handleAddToCart() {
    if (!result) return;
    trackAdd();
    onAddToCart(result);
    setResult(null);
    setProductId('');
  }

  return (
    <section id="calculator" className="relative overflow-hidden py-20 md:py-28">
      <div className="absolute inset-0 -z-10">
        <div className="absolute -top-32 left-1/2 h-[400px] w-[700px] -translate-x-1/2 rounded-full bg-accent-500/10 blur-[120px]" />
      </div>

      <div className="container-wide">
        <div className="mx-auto max-w-3xl text-center">
          <span className="eyebrow mx-auto">Калькулятор стоимости</span>
          <h2 className="mt-5 font-display text-4xl font-semibold text-white md:text-5xl">
            Узнайте вес и стоимость за 10 секунд
          </h2>
          <p className="mt-4 text-steel-300">
            Выберите позицию, укажите количество — получите вес и итоговую сумму.
          </p>
        </div>

        {/* Scroll anchor for catalog "Рассчитать" clicks */}
        <div id="calc-form" className="mt-12 glass-panel grid grid-cols-1 overflow-hidden shadow-panel lg:grid-cols-5">
          {/* Left: inputs */}
          <div className="border-b border-white/[0.06] p-6 md:p-8 lg:col-span-3 lg:border-b-0 lg:border-r">
            <div className="flex items-center gap-3">
              <div className="grid h-10 w-10 place-items-center rounded-md bg-accent-500/10 ring-1 ring-accent-500/30">
                <CalcIcon className="h-5 w-5 text-accent-500" />
              </div>
              <h3 className="font-display text-xl font-semibold text-white">Параметры расчёта</h3>
            </div>

            <div className="mt-7 space-y-5">

              {/* Step 1: Top-level category */}
              <div>
                <label className="mb-2 block text-xs font-medium uppercase tracking-wider text-steel-400">
                  Направление
                </label>
                {loading ? (
                  <div className="grid grid-cols-2 gap-2">
                    {Array.from({ length: 4 }).map((_, i) => (
                      <div key={i} className="h-10 animate-pulse rounded-md bg-white/5" />
                    ))}
                  </div>
                ) : (
                  <div className="grid grid-cols-2 gap-2">
                    {topCategories.map((c) => (
                      <button
                        key={c.slug}
                        type="button"
                        onClick={() => handleTopCatClick(c.slug)}
                        className={`rounded-md border px-3 py-2.5 text-xs font-medium transition ${
                          topSlug === c.slug
                            ? 'border-accent-500 bg-accent-500/10 text-accent-300'
                            : 'border-white/10 bg-white/[0.02] text-steel-300 hover:border-white/25 hover:text-white'
                        }`}
                      >
                        {c.title}
                      </button>
                    ))}
                  </div>
                )}
              </div>

              {/* Step 2: Subcategory */}
              {topSlug && availableSubcats.length > 0 && (
                <div className="animate-fade-up">
                  <label className="mb-2 flex items-center gap-1.5 text-xs font-medium uppercase tracking-wider text-steel-400">
                    <ChevronRight className="h-3 w-3 text-accent-500" />
                    {topCatData?.title} — подкатегория
                  </label>
                  <div className="flex flex-wrap gap-2">
                    {availableSubcats.map((s) => (
                      <button
                        key={s.slug}
                        type="button"
                        onClick={() => handleSubClick(s.slug)}
                        className={`rounded-md border px-3 py-2 text-xs font-medium transition ${
                          subSlug === s.slug
                            ? 'border-accent-500 bg-accent-500/10 text-accent-300'
                            : 'border-white/10 bg-white/[0.02] text-steel-300 hover:border-white/25 hover:text-white'
                        }`}
                      >
                        {s.title}
                      </button>
                    ))}
                  </div>
                </div>
              )}

              {/* Hints for steps */}
              {!topSlug && !loading && (
                <p className="text-sm text-steel-500">Выберите направление выше, чтобы продолжить.</p>
              )}
              {topSlug && availableSubcats.length > 0 && !subSlug && (
                <p className="text-sm text-steel-500">Выберите подкатегорию, чтобы увидеть позиции.</p>
              )}

              {/* Step 3: Product selector */}
              {productOptions.length > 0 && (
                <div className="animate-fade-up">
                  <label className="mb-2 flex items-center gap-1.5 text-xs font-medium uppercase tracking-wider text-steel-400">
                    <ChevronRight className="h-3 w-3 text-accent-500" />
                    {subCatData?.title ?? topCatData?.title} — сортамент
                  </label>

                  {/* Dropdown */}
                  <div className="relative">
                    <select
                      value={productId || product?.id || ''}
                      onChange={(e) => { setProductId(e.target.value); setResult(null); }}
                      className="field w-full appearance-none bg-graphite-900/70 pr-11 text-steel-200 font-medium"
                    >
                      <option value="" disabled className="bg-graphite-900 text-steel-500">
                        — выберите позицию —
                      </option>
                      {productOptions.map((p) => (
                        <option key={p.id} value={p.id} className="bg-graphite-900 font-normal">
                          {p.sheetAreaM2
                            ? `${p.label} · ${p.weightPerMeter} кг/м² · ${p.sheetAreaM2} м²`
                            : p.lengthM && p.lengthKind === 'nemer'
                            ? `${p.label} · ${p.weightPerMeter} кг/м · 1 шт ≈ ${p.lengthM} м`
                            : p.lengthM
                            ? `${p.label} · ${p.weightPerMeter} кг/м · ${p.lengthM} м`
                            : `${p.label} · ${p.weightPerMeter} ${p.isSheet ? 'кг/м²' : 'кг/м'}`}
                        </option>
                      ))}
                    </select>
                    <div className="pointer-events-none absolute inset-y-0 right-0 flex w-10 items-center justify-center rounded-r-[inherit] bg-accent-500/20 border-l border-accent-500/25">
                      <ChevronDown className="h-4 w-4 text-accent-400" />
                    </div>
                  </div>
                </div>
              )}

              {/* Step 4: Quantity */}
              {productOptions.length > 0 && (
                <div className="animate-fade-up">
                  <label className="mb-2 block text-xs font-medium uppercase tracking-wider text-steel-400">
                    Количество, {byPiece ? 'шт' : amountUnit}
                  </label>
                  <input
                    type="number"
                    min={byPiece ? 1 : 0}
                    step={byPiece ? 1 : 'any'}
                    value={qtyStr}
                    onChange={(e) => { setQtyStr(e.target.value); setResult(null); }}
                    onBlur={() => {
                      if (byPiece ? (!qtyStr || parseInt(qtyStr, 10) < 1) : !(amountM > 0)) setQtyStr(byPiece ? '1' : '10');
                    }}
                    className="field font-mono"
                  />
                  {isNemer && product && (
                    <p className="mt-1.5 text-[12px] text-steel-500">
                      {capitalize(`${product.lengthText ? `${product.lengthText} — ` : ''}вес считаем по ${String(product.lengthM).replace('.', ',')} м за штуку`)}
                    </p>
                  )}
                  {!byPiece && product && (
                    <p className="mt-1.5 text-[12px] text-steel-500">
                      {capitalize(`${product.lengthText ? `${product.lengthText} — ` : ''}считаем по ${amountUnit === 'м²' ? 'площади' : 'метражу'}`)}
                    </p>
                  )}
                </div>
              )}

              {productOptions.length > 0 && (
                <button
                  type="button"
                  onClick={handleCalc}
                  disabled={!product}
                  className="btn-primary w-full sm:w-auto disabled:opacity-50"
                >
                  Рассчитать
                  <ArrowRight className="h-4 w-4" />
                </button>
              )}
            </div>
          </div>

          {/* Right: result */}
          <div className="relative flex flex-col justify-between bg-gradient-to-br from-graphite-900 via-graphite-900 to-graphite-950 p-6 md:p-8 lg:col-span-2">
            <div>
              <span className="font-mono text-[11px] uppercase tracking-[0.2em] text-steel-500">
                Итоговый расчёт
              </span>

              {!result ? (
                <div className="mt-8 space-y-4">
                  <div className="h-6 w-3/4 animate-pulse rounded bg-white/5" />
                  <div className="h-12 w-full animate-pulse rounded bg-white/5" />
                  <div className="h-12 w-full animate-pulse rounded bg-white/5" />
                  <p className="pt-6 text-sm text-steel-500">
                    Заполните параметры слева и нажмите «Рассчитать».
                  </p>
                </div>
              ) : (
                <div className="mt-5 space-y-5">
                  <div className="text-sm text-steel-300">{result.productLabel}</div>

                  <div className="rounded-xl border border-white/[0.08] bg-graphite-950/70 p-5">
                    <div className="text-[11px] uppercase tracking-wider text-steel-500">Общий вес</div>
                    <div className="mt-1 font-display text-3xl font-semibold text-white">
                      {result.weightKg.toLocaleString('ru-RU', { maximumFractionDigits: 1 })}{' '}
                      <span className="text-base text-steel-400">кг</span>
                    </div>
                    <div className="mt-1 font-mono text-xs text-steel-500">
                      {(result.weightKg / 1000).toLocaleString('ru-RU', { maximumFractionDigits: 3 })} т
                      · {itemQtyText(result)}
                    </div>
                  </div>

                  <div className="rounded-xl border border-accent-500/30 bg-accent-500/10 p-5">
                    <div className="text-[11px] uppercase tracking-wider text-accent-300">Итоговая стоимость</div>
                    <div className="mt-1 font-display text-3xl font-semibold text-white">
                      {result.totalPrice.toLocaleString('ru-RU', { maximumFractionDigits: 0 })}{' '}
                      <span className="text-base text-accent-300">₽</span>
                    </div>
                    <div className="mt-1 font-mono text-xs text-accent-300/80">
                      {result.pricePerTon.toLocaleString('ru-RU')} ₽/т
                    </div>
                  </div>

                  <div className="flex flex-col gap-2.5 sm:flex-row">
                    <button
                      type="button"
                      onClick={handleAddToCart}
                      className="btn-ghost w-full sm:flex-1"
                    >
                      Добавить в заказ
                    </button>
                    <button
                      type="button"
                      onClick={() => { trackAdd(); ymGoal('checkout_click'); onOrder(result); }}
                      className="btn-primary w-full sm:flex-1"
                    >
                      Оформить заказ
                      <ArrowRight className="h-4 w-4" />
                    </button>
                  </div>
                  <p className="text-[11px] text-steel-500">
                    «Добавить в заказ» — продолжить выбирать позиции здесь же.
                    «Оформить заказ» — перейти к отправке заявки.
                  </p>

                  <div className="flex items-center gap-2 text-xs text-steel-400">
                    <CheckCircle2 className="h-4 w-4 text-accent-500" />
                    Точную цену подтвердим в коммерческом предложении
                  </div>
                </div>
              )}
            </div>
          </div>
        </div>
      </div>
    </section>
  );
}
