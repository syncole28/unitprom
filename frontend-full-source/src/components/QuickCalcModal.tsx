import { useEffect, useMemo, useState } from 'react';
import { X, ArrowRight, Package, Minus, Plus, Save } from 'lucide-react';
import { CutDiscIcon } from './CutDiscIcon';
import { pieceWeightKg } from '../lib/products';
import type { CalcOption } from '../lib/products';
import type { CartItem, CutInfo } from '../App';
import { ymGoal, ymEcommerceAdd } from '../lib/ym';
import { itemEcommerce, plural } from '../lib/cartFormat';

export type Unit = 'pcs' | 'm' | 't';

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
  unit: Unit;
  cut?: CutInfo;
};

type Basis = {
  id: string | null;
  label: string;
  // false — позицию не продают штуками (немерная, в бухте, длина не задана):
  // считаем только метры (у листа м²) и тонны, без «шт».
  pieceable: boolean;
  // Немерная: только штуки и тонны, 1 шт — по расчётной длине lengthM (≈11,5 м)
  nemer: boolean;
  // Лист: «м²» вместо «м», «Площадь» вместо «Длины».
  isSheet: boolean;
  lengthM: number;
  // Для листов — площадь одного листа (м²)
  areaM2: number | null;
  weightPerPieceKg: number;
  // Вес 1 м (у листа 1 м²) — для позиций без штуки
  weightPerUnitKg: number;
  // «немерная 9–12 м», «в бухте»
  lengthText: string;
  pricePerTon: number;
  inStock: number | null;
};

// Размер одной штуки в единицах переключателя «м» / «м²».
function sizePerPiece(basis: Basis): number {
  return basis.areaM2 ?? basis.lengthM;
}

export type QuickCalcTarget =
  | { mode: 'add'; product: CalcOption }
  | { mode: 'edit'; item: CartItem };

type Props = {
  target: QuickCalcTarget;
  onClose: () => void;
  onAddToCart: (payload: Result) => void;
  onOrder: (payload: Result) => void;
  onSave: (payload: Result) => void;
};

function formatNum(n: number, maxDigits = 2): string {
  return n.toLocaleString('ru-RU', { maximumFractionDigits: maxDigits });
}

function basisFromTarget(target: QuickCalcTarget): Basis {
  if (target.mode === 'add') {
    const p = target.product;
    const pieceKg = pieceWeightKg(p);
    return {
      id: p.id,
      label: p.label,
      pieceable: pieceKg !== null,
      nemer: p.lengthKind === 'nemer' && pieceKg !== null,
      isSheet: !!p.isSheet,
      lengthM: p.lengthM ?? 0,
      areaM2: p.sheetAreaM2 ?? null,
      weightPerPieceKg: pieceKg ?? 0,
      weightPerUnitKg: p.weightPerMeter,
      lengthText: p.lengthText ?? '',
      pricePerTon: p.pricePerTon,
      inStock: p.inStock ?? null,
    };
  }
  const i = target.item;
  const pricePerTon = (i.totalPrice / (i.weightKg / 1000)) || 0;
  if (!(i.qty > 0)) {
    return {
      id: null,
      label: i.productLabel,
      pieceable: false,
      nemer: false,
      isSheet: i.amountUnit === 'м²',
      lengthM: 0,
      areaM2: null,
      weightPerPieceKg: 0,
      weightPerUnitKg: i.amount ? i.weightKg / i.amount : 0,
      lengthText: '',
      pricePerTon,
      inStock: null,
    };
  }
  const weightPerPieceKg = i.weightKg / i.qty;
  return {
    id: null,
    label: i.productLabel,
    pieceable: true,
    nemer: !!i.nemer,
    isSheet: !!i.areaM2,
    lengthM: i.lengthM,
    areaM2: i.areaM2 ?? null,
    weightPerPieceKg,
    weightPerUnitKg: weightPerPieceKg / (i.areaM2 ?? i.lengthM),
    lengthText: '',
    pricePerTon,
    inStock: null,
  };
}

function round3(n: number): number {
  return Math.round(n * 1000) / 1000;
}

// Позиции без штуки: метры (м²) → значение поля для выбранной единицы.
function amountStrFor(amountM: number, u: Unit, basis: Basis): string {
  if (u === 't') return String(round3((amountM * basis.weightPerUnitKg) / 1000));
  return String(round3(amountM));
}

function defaultAmountFor(unit: Unit, basis: Basis): string {
  if (!basis.pieceable) return unit === 't' ? '1' : '10';
  return amountForQtyIn(1, unit, basis);
}

// Переводит количество целых штук в величину для выбранной единицы.
function amountForQtyIn(qty: number, u: Unit, basis: Basis): string {
  if (u === 'pcs') return String(qty);
  if (u === 'm') return String(Math.round(qty * sizePerPiece(basis) * 1000) / 1000);
  return String(Math.round(((qty * basis.weightPerPieceKg) / 1000) * 1000) / 1000);
}

// Сколько целых штук нужно на введённую величину. Поле показывает метры и тонны
// с точностью до 3 знаков, поэтому значение, которое отличается от целого числа
// штук только этим округлением (12 шт = 1,6956 т → «1,696»), считаем ровно этим
// числом штук. Иначе округление вверх давало лишнюю штуку и «−» не уменьшал количество.
function qtyFromAmount(amount: number, perPiece: number, tolerance: number): number {
  const nearest = Math.round(amount / perPiece);
  const tol = Math.min(tolerance, perPiece / 2) + 1e-9;
  if (nearest > 0 && Math.abs(nearest * perPiece - amount) <= tol) return nearest;
  return Math.ceil(amount / perPiece - 1e-9);
}

export function QuickCalcModal({ target, onClose, onAddToCart, onOrder, onSave }: Props) {
  const basis = useMemo(() => basisFromTarget(target), [target]);
  const isEdit = target.mode === 'edit';

  // При редактировании открываем в тех единицах, в которых позицию добавили.
  const editItem = target.mode === 'edit' ? target.item : null;
  // Позиции без штуки по умолчанию считаем в тоннах.
  const [unit, setUnit] = useState<Unit>(editItem?.unit ?? (basis.pieceable ? 'pcs' : 't'));
  // Резка: длинномер — длина заготовки, лист — размеры текстом.
  const [cutOn, setCutOn] = useState<boolean>(!!editItem?.cut);
  const [cutLenStr, setCutLenStr] = useState<string>(editItem?.cut?.lengthM ? String(editItem.cut.lengthM) : '');
  const [cutSizes, setCutSizes] = useState<string>(editItem?.cut?.sizes ?? '');
  const [cutHalf, setCutHalf] = useState<boolean>(!!editItem?.cut?.half);
  const [cutNote, setCutNote] = useState<string>(editItem?.cut?.note ?? '');
  const [amountStr, setAmountStr] = useState<string>(() => {
    if (!basis.pieceable) {
      return editItem ? amountStrFor(editItem.amount ?? 0, editItem.unit ?? 't', basis) : defaultAmountFor('t', basis);
    }
    return editItem ? amountForQtyIn(editItem.qty, editItem.unit ?? 'pcs', basis) : '1';
  });

  // Переводит количество целых штук в величину для текущей единицы —
  // используется и для шага +/-, и чтобы после округления поле ввода
  // сразу показывало реально достижимое значение, а не введённое "на глаз".
  function amountForQty(qty: number, u: Unit): string {
    return amountForQtyIn(qty, u, basis);
  }

  useEffect(() => {
    document.body.style.overflow = 'hidden';
    function onKey(e: KeyboardEvent) {
      if (e.key === 'Escape') onClose();
    }
    window.addEventListener('keydown', onKey);
    return () => {
      document.body.style.overflow = '';
      window.removeEventListener('keydown', onKey);
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  // Смена единиц пересчитывает уже выбранное количество, а не сбрасывает его.
  function handleUnitChange(u: Unit) {
    setUnit(u);
    if (!basis.pieceable) {
      setAmountStr(calc.amount > 0 ? amountStrFor(calc.amount, u, basis) : defaultAmountFor(u, basis));
      return;
    }
    setAmountStr(
      calc.qty > 0 ? amountForQty(calc.qty, u) : defaultAmountFor(u, basis),
    );
  }

  // Шаг — всегда ровно на одну штуку в пересчёте на текущую единицу
  // (а не на фиксированную величину вроде "+0.1 т"), поэтому результат
  // шага никогда не требует дальнейшего округления.
  function handleStep(dir: 1 | -1) {
    if (!basis.pieceable) {
      // Без штук: шаг 1 м (м²) или 0,1 т, до ближайшего целого шага
      const step = unit === 't' ? 0.1 : 1;
      const cur = parseFloat(amountStr.replace(',', '.')) || 0;
      const k = dir > 0 ? Math.floor(cur / step + 1e-9) + 1 : Math.ceil(cur / step - 1e-9) - 1;
      setAmountStr(String(round3(Math.max(0, k * step))));
      return;
    }
    const newQty = Math.max(0, calc.qty + dir);
    setAmountStr(amountForQty(newQty, unit));
  }

  // Если ввели вручную не кратное штуке значение — после того как ушли
  // с поля, подставляем реально достижимое (уже округлённое) число,
  // чтобы поле не расходилось с тем, что показано ниже.
  function handleBlur() {
    if (basis.pieceable && calc.qty > 0) {
      setAmountStr(amountForQty(calc.qty, unit));
    }
  }

  const calc = useMemo(() => {
    const parsed = parseFloat(amountStr.replace(',', '.'));
    if (!parsed || parsed <= 0) {
      return { qty: 0, amount: 0, totalWeightKg: 0, totalLengthM: 0, totalAreaM2: 0, totalPrice: 0, roundNote: null as string | null };
    }

    if (!basis.pieceable) {
      const amount = unit === 't'
        ? (basis.weightPerUnitKg > 0 ? (parsed * 1000) / basis.weightPerUnitKg : 0)
        : parsed;
      const totalWeightKg = amount * basis.weightPerUnitKg;
      return {
        qty: 0,
        amount,
        totalWeightKg,
        totalLengthM: basis.isSheet ? 0 : amount,
        totalAreaM2: basis.isSheet ? amount : 0,
        totalPrice: (totalWeightKg / 1000) * basis.pricePerTon,
        roundNote: null as string | null,
      };
    }

    let qty: number;
    let roundNote: string | null = null;

    if (unit === 'pcs') {
      qty = Math.ceil(parsed - 1e-9);
      if (Math.abs(qty - parsed) > 0.001) {
        roundNote = `Округлено до ${qty} шт`;
      }
    } else if (unit === 'm') {
      const size = sizePerPiece(basis);
      const sizeUnit = basis.areaM2 !== null ? 'м²' : 'м';
      qty = qtyFromAmount(parsed, size, 0.0005);
      const actual = qty * size;
      if (Math.abs(actual - parsed) > 0.001) {
        roundNote = `Округлено до ${qty} шт × ${formatNum(size, 3)} ${sizeUnit} = ${formatNum(actual, 3)} ${sizeUnit}`;
      }
    } else {
      qty = qtyFromAmount(parsed, basis.weightPerPieceKg / 1000, 0.0005);
      const actualT = (qty * basis.weightPerPieceKg) / 1000;
      if (Math.abs(actualT - parsed) > 0.0005) {
        roundNote = `Округлено до ${qty} шт = ${formatNum(actualT, 3)} т`;
      }
    }

    qty = Math.max(qty, 0);
    const totalWeightKg = qty * basis.weightPerPieceKg;
    const totalLengthM = qty * basis.lengthM;
    const totalAreaM2 = basis.areaM2 !== null ? qty * basis.areaM2 : 0;
    const totalPrice = (totalWeightKg / 1000) * basis.pricePerTon;

    return { qty, amount: 0, totalWeightKg, totalLengthM, totalAreaM2, totalPrice, roundNote };
  }, [amountStr, unit, basis]);

  // «Пополам» — только у позиций со штукой известной длины (у листа — с известным размером).
  const canHalf = basis.pieceable && basis.lengthM > 0 && (!basis.isSheet || basis.areaM2 !== null);
  const halfOn = cutOn && cutHalf && canHalf;

  // Лист пополам: режем поперёк длины, ширина сохраняется.
  const sheetHalf = useMemo(() => {
    if (!halfOn || !basis.isSheet || basis.areaM2 === null) return null;
    const wMm = Math.round((basis.areaM2 / basis.lengthM) * 1000);
    const halfMm = Math.round((basis.lengthM * 1000) / 2);
    const total = 2 * calc.qty;
    const size = `${wMm}×${halfMm} мм`;
    return {
      lines: [
        `Из 1 листа ${wMm}×${Math.round(basis.lengthM * 1000)} мм — 2 заготовки ${size}`,
        ...(calc.qty > 0
          ? [`Всего: ${total} ${plural(total, 'заготовка', 'заготовки', 'заготовок')}, ${calc.qty} ${plural(calc.qty, 'рез', 'реза', 'резов')}`]
          : []),
      ],
      text: `пополам, ${total} ${plural(total, 'заготовка', 'заготовки', 'заготовок')} ${size}`,
    };
  }, [halfOn, basis, calc.qty]);

  // Раскрой: сколько заготовок выйдет из штуки (или из всего метража у бухты).
  const cutPlan = useMemo(() => {
    if (!cutOn || basis.isSheet) return null;
    const len = halfOn ? basis.lengthM / 2 : parseFloat(cutLenStr.replace(',', '.'));
    if (!len || len <= 0) return null;
    const approx = basis.nemer ? '≈' : '';
    if (basis.pieceable) {
      const pieceLen = basis.lengthM;
      if (!(pieceLen > 0)) return null;
      if (len > pieceLen + 1e-9) {
        return { error: `Заготовка длиннее одной штуки (${approx}${formatNum(pieceLen)} м)` };
      }
      const perPiece = Math.floor(pieceLen / len + 1e-9);
      const rest = Math.max(0, pieceLen - perPiece * len);
      const cutsPerPiece = rest < 0.0005 ? perPiece - 1 : perPiece;
      const total = perPiece * calc.qty;
      const cuts = cutsPerPiece * calc.qty;
      if (halfOn) {
        return {
          len,
          total,
          lines: [
            `Из 1 шт ${approx}${formatNum(pieceLen)} м — 2 заготовки по ${approx}${formatNum(len, 3)} м`,
            ...(calc.qty > 0
              ? [`Всего: ${total} ${plural(total, 'заготовка', 'заготовки', 'заготовок')}, ${cuts} ${plural(cuts, 'рез', 'реза', 'резов')}`]
              : []),
          ],
          text: `пополам, ${total} ${plural(total, 'заготовка', 'заготовки', 'заготовок')} по ${approx}${formatNum(len, 3)} м`,
        };
      }
      return {
        len,
        total,
        lines: [
          `Из 1 шт ${approx}${formatNum(pieceLen)} м — ${perPiece} ${plural(perPiece, 'заготовка', 'заготовки', 'заготовок')} по ${formatNum(len, 3)} м, остаток ${approx}${formatNum(rest, 3)} м`,
          ...(calc.qty > 0
            ? [`Всего: ${approx}${total} ${plural(total, 'заготовка', 'заготовки', 'заготовок')}, ${approx}${cuts} ${plural(cuts, 'рез', 'реза', 'резов')}`]
            : []),
        ],
        text: `по ${formatNum(len, 3)} м, ${approx}${total} ${plural(total, 'заготовка', 'заготовки', 'заготовок')}`,
      };
    }
    // Бухта и позиции без длины: режем из всего метража.
    if (!(calc.amount > 0)) return null;
    const total = Math.floor(calc.amount / len + 1e-9);
    if (total < 1) return { error: `Заготовка длиннее, чем ${formatNum(calc.amount, 3)} м в заказе` };
    const rest = Math.max(0, calc.amount - total * len);
    return {
      len,
      total,
      lines: [
        `Из ${formatNum(calc.amount, 3)} м — ${total} ${plural(total, 'заготовка', 'заготовки', 'заготовок')} по ${formatNum(len, 3)} м, остаток ${formatNum(rest, 3)} м`,
      ],
      text: `по ${formatNum(len, 3)} м, ${total} ${plural(total, 'заготовка', 'заготовки', 'заготовок')}`,
    };
  }, [cutOn, halfOn, cutLenStr, basis, calc.qty, calc.amount]);

  const cutError = cutPlan && 'error' in cutPlan ? cutPlan.error : null;

  function buildCut(): CutInfo | undefined {
    if (!cutOn) return undefined;
    const note = cutNote.trim();
    const parts: string[] = [];
    let lengthM: number | undefined;
    let sizes: string | undefined;
    if (basis.isSheet && sheetHalf) {
      parts.push(sheetHalf.text);
    } else if (basis.isSheet) {
      sizes = cutSizes.trim() || undefined;
      if (sizes) parts.push(sizes);
    } else if (cutPlan && !('error' in cutPlan)) {
      lengthM = cutPlan.len;
      parts.push(cutPlan.text);
    }
    if (note) parts.push(note);
    const text = `Резка: ${parts.length ? parts.join('; ') : 'размеры уточнить'}`;
    return {
      ...(halfOn ? { half: true as const } : {}),
      ...(lengthM ? { lengthM } : {}),
      ...(sizes ? { sizes } : {}),
      ...(note ? { note } : {}),
      text,
    };
  }

  const canSubmit = (basis.pieceable ? calc.qty > 0 : calc.amount > 0) && !cutError;

  function buildResult(): Result {
    const cut = buildCut();
    if (!basis.pieceable) {
      return {
        weightKg: calc.totalWeightKg,
        totalPrice: calc.totalPrice,
        pricePerTon: basis.pricePerTon,
        productLabel: basis.label,
        qty: 0,
        lengthM: 0,
        amount: round3(calc.amount),
        amountUnit: basis.isSheet ? 'м²' : 'м',
        unit,
        ...(cut ? { cut } : {}),
      };
    }
    return {
      weightKg: calc.totalWeightKg,
      totalPrice: calc.totalPrice,
      pricePerTon: basis.pricePerTon,
      productLabel: basis.label,
      qty: calc.qty,
      lengthM: basis.lengthM,
      ...(basis.areaM2 !== null ? { areaM2: basis.areaM2 } : {}),
      ...(basis.nemer ? { nemer: true } : {}),
      unit,
      ...(cut ? { cut } : {}),
    };
  }

  function trackAdd() {
    ymGoal('cart_add');
    if (basis.id) {
      ymEcommerceAdd({
        id: basis.id,
        name: basis.label,
        ...itemEcommerce({ qty: calc.qty, totalPrice: calc.totalPrice }),
      });
    }
  }

  const unitLabels: { key: Unit; label: string }[] = [
    ...(basis.pieceable ? [{ key: 'pcs' as Unit, label: 'шт' }] : []),
    ...(basis.nemer ? [] : [{ key: 'm' as Unit, label: basis.isSheet ? 'м²' : 'м' }]),
    { key: 't', label: 'т' },
  ];

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center p-4">
      <div className="absolute inset-0 bg-graphite-950/80 backdrop-blur-sm" onClick={onClose} />

      <div className="glass-panel relative max-h-[calc(100vh-2rem)] w-full max-w-md overflow-y-auto bg-graphite-900/95 shadow-panel animate-fade-up">
        <div className="flex items-start justify-between gap-3 border-b border-white/[0.08] px-5 py-4">
          <div className="min-w-0">
            <div className="font-display text-base font-semibold leading-snug text-white">{basis.label}</div>
            <div className="mt-1 flex items-center gap-2 text-[12px] text-steel-400">
              <span>
                {basis.nemer
                  ? `${formatNum(basis.weightPerPieceKg / basis.lengthM, 4)} кг/м · ${basis.lengthText ? `${basis.lengthText}, ` : ''}1 шт ≈ ${formatNum(basis.lengthM)} м`
                  : !basis.pieceable
                  ? `${formatNum(basis.weightPerUnitKg, 4)} ${basis.isSheet ? 'кг/м²' : 'кг/м'}${basis.lengthText ? ` · ${basis.lengthText}` : ''}`
                  : basis.areaM2 !== null
                  ? `${formatNum(basis.weightPerPieceKg / basis.areaM2, 4)} кг/м² · ${formatNum(basis.areaM2 / basis.lengthM, 3)}×${formatNum(basis.lengthM, 3)} м`
                  : `${formatNum(basis.weightPerPieceKg / basis.lengthM, 4)} кг/м · ${formatNum(basis.lengthM)} м/шт`}
              </span>
              {basis.inStock !== null && (
                basis.inStock >= 1 ? (
                  <span className="font-semibold text-emerald-400">В наличии</span>
                ) : (
                  <span className="text-steel-500">Под заказ</span>
                )
              )}
            </div>
          </div>
          <button
            type="button"
            onClick={onClose}
            className="shrink-0 rounded-md p-1 text-steel-400 hover:bg-white/5 hover:text-white"
            aria-label="Закрыть"
          >
            <X className="h-5 w-5" />
          </button>
        </div>

        <div className="p-5">
          <label className="mb-2 block text-xs font-medium uppercase tracking-wider text-steel-400">
            Введите количество
          </label>
          <div className="flex gap-2">
            <div className="flex flex-1 items-stretch overflow-hidden rounded-md border border-white/10 bg-graphite-900/70 focus-within:border-accent-500/70">
              <button
                type="button"
                onClick={() => handleStep(-1)}
                className="grid w-10 shrink-0 place-items-center text-steel-400 transition hover:bg-white/[0.06] hover:text-white"
                aria-label="Уменьшить"
              >
                <Minus className="h-3.5 w-3.5" />
              </button>
              <input
                type="number"
                min={0}
                step="any"
                value={amountStr}
                onChange={(e) => setAmountStr(e.target.value)}
                onBlur={handleBlur}
                className="w-full border-0 bg-transparent px-2 text-center font-mono text-lg text-white outline-none"
                autoFocus
              />
              <button
                type="button"
                onClick={() => handleStep(1)}
                className="grid w-10 shrink-0 place-items-center text-steel-400 transition hover:bg-white/[0.06] hover:text-white"
                aria-label="Увеличить"
              >
                <Plus className="h-3.5 w-3.5" />
              </button>
            </div>
            <div className="flex shrink-0 overflow-hidden rounded-md border border-white/10">
              {unitLabels.map((u) => (
                <button
                  key={u.key}
                  type="button"
                  onClick={() => handleUnitChange(u.key)}
                  className={`px-3.5 text-sm font-semibold transition ${
                    unit === u.key
                      ? 'bg-accent-500 text-white'
                      : 'bg-white/[0.03] text-steel-400 hover:bg-white/[0.08] hover:text-white'
                  }`}
                >
                  {u.label}
                </button>
              ))}
            </div>
          </div>

          {calc.roundNote && (
            <p className="mt-2 text-[12px] text-accent-400">{calc.roundNote}</p>
          )}

          <div className={`mt-5 grid gap-2 ${basis.pieceable ? 'grid-cols-3' : 'grid-cols-2'}`}>
            {basis.pieceable && (
              <div className="rounded-lg border border-white/[0.08] bg-graphite-950/60 px-3 py-2.5">
                <div className="text-[10px] uppercase tracking-wider text-steel-500">Штук</div>
                <div className="mt-0.5 font-mono text-sm font-semibold text-white">{calc.qty}</div>
              </div>
            )}
            <div className="rounded-lg border border-white/[0.08] bg-graphite-950/60 px-3 py-2.5">
              {basis.isSheet ? (
                <>
                  <div className="text-[10px] uppercase tracking-wider text-steel-500">Площадь</div>
                  <div className="mt-0.5 font-mono text-sm font-semibold text-white">{formatNum(calc.totalAreaM2, 3)} м²</div>
                </>
              ) : (
                <>
                  <div className="text-[10px] uppercase tracking-wider text-steel-500">Длина</div>
                  <div className="mt-0.5 font-mono text-sm font-semibold text-white">{basis.nemer ? '≈' : ''}{formatNum(calc.totalLengthM)} м</div>
                </>
              )}
            </div>
            <div className="rounded-lg border border-white/[0.08] bg-graphite-950/60 px-3 py-2.5">
              <div className="text-[10px] uppercase tracking-wider text-steel-500">Вес</div>
              <div className="mt-0.5 font-mono text-sm font-semibold text-white">{formatNum(calc.totalWeightKg, 1)} кг</div>
            </div>
          </div>

          <div className="mt-3 rounded-lg border border-white/[0.08] bg-graphite-950/40 px-3.5 py-3">
            <label className="flex cursor-pointer select-none items-center gap-2.5 text-sm font-medium text-white">
              <input
                type="checkbox"
                checked={cutOn}
                onChange={(e) => setCutOn(e.target.checked)}
                className="h-4 w-4 shrink-0 cursor-pointer accent-accent-500"
              />
              <CutDiscIcon className="h-4 w-4 text-accent-400" />
              Нужна резка
            </label>
            {cutOn && (
              <div className="mt-3 space-y-2.5">
                {canHalf && (
                  <label className="flex cursor-pointer select-none items-start gap-2.5 rounded-md border border-white/10 bg-graphite-900/50 px-3 py-2">
                    <input
                      type="checkbox"
                      checked={cutHalf}
                      onChange={(e) => setCutHalf(e.target.checked)}
                      className="mt-0.5 h-4 w-4 shrink-0 cursor-pointer accent-accent-500"
                    />
                    <span>
                      <span className="block text-sm font-medium text-white">Резка пополам</span>
                      <span className="block text-[11px] leading-snug text-steel-400">
                        {basis.isSheet
                          ? 'Поперёк, на 2 равные части'
                          : `По ${basis.nemer ? '≈' : ''}${formatNum(basis.lengthM / 2, 3)} м`}
                      </span>
                    </span>
                  </label>
                )}
                {halfOn ? null : basis.isSheet ? (
                  <div>
                    <div className="mb-1 text-[10px] uppercase tracking-wider text-steel-500">Размеры заготовок</div>
                    <input
                      type="text"
                      value={cutSizes}
                      onChange={(e) => setCutSizes(e.target.value)}
                      placeholder="например, 500×1000 мм — 10 шт"
                      maxLength={200}
                      className="w-full rounded-md border border-white/10 bg-graphite-900/70 px-3 py-2 text-sm text-white outline-none placeholder:text-steel-600 focus:border-accent-500/70"
                    />
                  </div>
                ) : (
                  <div>
                    <div className="mb-1 text-[10px] uppercase tracking-wider text-steel-500">Длина заготовки</div>
                    <div className="flex items-center gap-2">
                      <input
                        type="number"
                        min={0}
                        step="any"
                        value={cutLenStr}
                        onChange={(e) => setCutLenStr(e.target.value)}
                        placeholder="1,5"
                        className="w-28 rounded-md border border-white/10 bg-graphite-900/70 px-3 py-2 text-center font-mono text-sm text-white outline-none placeholder:text-steel-600 focus:border-accent-500/70"
                      />
                      <span className="text-sm text-steel-400">м</span>
                    </div>
                  </div>
                )}
                {cutError && <p className="text-[12px] text-red-400">{cutError}</p>}
                {sheetHalf && (
                  <div className="space-y-0.5 text-[12px] text-accent-300">
                    {sheetHalf.lines.map((l) => (
                      <div key={l}>{l}</div>
                    ))}
                  </div>
                )}
                {cutPlan && !('error' in cutPlan) && (
                  <div className="space-y-0.5 text-[12px] text-accent-300">
                    {cutPlan.lines.map((l) => (
                      <div key={l}>{l}</div>
                    ))}
                  </div>
                )}
                <input
                  type="text"
                  value={cutNote}
                  onChange={(e) => setCutNote(e.target.value)}
                  placeholder="Комментарий к резке (необязательно)"
                  maxLength={200}
                  className="w-full rounded-md border border-white/10 bg-graphite-900/70 px-3 py-2 text-sm text-white outline-none placeholder:text-steel-600 focus:border-accent-500/70"
                />
                <p className="text-[11px] text-steel-500">Стоимость резки рассчитает менеджер</p>
              </div>
            )}
          </div>

          <div className="mt-3 rounded-xl border border-accent-500/30 bg-accent-500/10 px-4 py-3.5">
            <div className="text-[11px] uppercase tracking-wider text-accent-300">Сумма</div>
            <div className="mt-0.5 font-display text-2xl font-semibold text-white">
              {formatNum(calc.totalPrice, 0)} <span className="text-sm text-accent-300">₽</span>
            </div>
            <div className="mt-0.5 text-[11px] text-accent-300/80">
              {formatNum(basis.pricePerTon, 0)} ₽/т · ориентировочно
            </div>
          </div>

          {isEdit ? (
            <div className="mt-4">
              <button
                type="button"
                disabled={!canSubmit}
                onClick={() => { ymGoal('cart_edit'); onSave(buildResult()); }}
                className="btn-primary w-full disabled:cursor-not-allowed disabled:opacity-40"
              >
                <Save className="h-4 w-4" />
                Сохранить
              </button>
            </div>
          ) : (
            <div className="mt-4 flex flex-col gap-2.5 sm:flex-row">
              <button
                type="button"
                disabled={!canSubmit}
                onClick={() => { trackAdd(); onAddToCart(buildResult()); }}
                className="btn-primary w-full sm:flex-1 disabled:cursor-not-allowed disabled:opacity-40"
              >
                <Package className="h-4 w-4" />
                Добавить в заказ
              </button>
              <button
                type="button"
                disabled={!canSubmit}
                onClick={() => { trackAdd(); ymGoal('checkout_click'); onOrder(buildResult()); }}
                className="btn-ghost w-full sm:flex-1 disabled:cursor-not-allowed disabled:opacity-40"
              >
                Оформить заказ
                <ArrowRight className="h-4 w-4" />
              </button>
            </div>
          )}
        </div>
      </div>
    </div>
  );
}
