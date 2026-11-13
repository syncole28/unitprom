import { useEffect, useRef, useState } from 'react';
import { ShoppingCart, ChevronUp, ChevronDown, X, ArrowRight, Trash2, Pencil, Plus } from 'lucide-react';
import { CutDiscIcon } from './CutDiscIcon';
import type { CartItem } from '../App';
import { ymGoal } from '../lib/ym';
import { itemQtyText } from '../lib/cartFormat';

const BUMP_MS = 1200;

type Props = {
  items: CartItem[];
  onRemoveItem: (id: string) => void;
  onEditItem: (item: CartItem) => void;
  onAddMore: () => void;
  onCheckout: () => void;
};

function formatRub(value: number) {
  return value.toLocaleString('ru-RU', { maximumFractionDigits: 0 });
}

export function OrderWidget({ items, onRemoveItem, onEditItem, onAddMore, onCheckout }: Props) {
  const [open, setOpen] = useState(false);
  const [bump, setBump] = useState(false);
  const prevCount = useRef(items.length);

  // Подсветить язычок, когда позиция добавлена (список при этом не открываем).
  useEffect(() => {
    if (items.length > prevCount.current) {
      setBump(true);
      const t = setTimeout(() => setBump(false), BUMP_MS);
      prevCount.current = items.length;
      return () => clearTimeout(t);
    }
    prevCount.current = items.length;
  }, [items.length]);

  useEffect(() => {
    if (!open) return;
    const onKey = (e: KeyboardEvent) => {
      if (e.key === 'Escape') setOpen(false);
    };
    document.addEventListener('keydown', onKey);
    return () => document.removeEventListener('keydown', onKey);
  }, [open]);

  useEffect(() => {
    if (items.length === 0) setOpen(false);
  }, [items.length]);

  if (items.length === 0) return null;

  const total = items.reduce((sum, i) => sum + i.totalPrice, 0);
  const totalWeight = items.reduce((sum, i) => sum + i.weightKg, 0);
  const itemsWord = items.length === 1 ? 'позиция' : items.length < 5 ? 'позиции' : 'позиций';

  const header = (
    <div className="flex items-center justify-between border-b border-white/[0.08] px-4 py-3">
      <span className="font-display text-sm font-semibold text-white">
        Ваш заказ · {items.length} {itemsWord}
      </span>
      <button
        type="button"
        onClick={() => setOpen(false)}
        className="text-steel-400 hover:text-white"
        aria-label="Свернуть"
      >
        <X className="h-4 w-4" />
      </button>
    </div>
  );

  const list = (
    <>
      {items.map((item) => (
        <div
          key={item.id}
          role="button"
          tabIndex={0}
          title="Изменить количество"
          onClick={() => { setOpen(false); onEditItem(item); }}
          onKeyDown={(e) => {
            if (e.target === e.currentTarget && (e.key === 'Enter' || e.key === ' ')) {
              e.preventDefault();
              setOpen(false);
              onEditItem(item);
            }
          }}
          className="group/item flex cursor-pointer items-start justify-between gap-3 px-4 py-3 transition hover:bg-white/[0.04] focus-visible:bg-white/[0.04] focus-visible:outline-none"
        >
          <div className="min-w-0">
            <div className="line-clamp-2 text-sm font-medium leading-snug text-white group-hover/item:text-accent-300" title={item.productLabel}>{item.productLabel}</div>
            <div className="mt-0.5 text-[12px] text-steel-400">
              {itemQtyText(item)} · {item.weightKg.toFixed(1)} кг
            </div>
            {item.cut && (
              <div className="mt-0.5 flex items-start gap-1.5 text-[12px] text-steel-300">
                <CutDiscIcon className="mt-[3px] h-3 w-3 shrink-0 text-accent-400" />
                <span className="line-clamp-2">{item.cut.text}</span>
              </div>
            )}
            <div className="mt-0.5 text-[13px] font-semibold text-accent-400">
              ~{formatRub(item.totalPrice)} ₽
            </div>
          </div>
          <div className="flex shrink-0 flex-col items-center gap-2">
            <button
              type="button"
              onClick={(e) => { e.stopPropagation(); ymGoal('cart_remove'); onRemoveItem(item.id); }}
              className="text-steel-500 hover:text-red-400"
              aria-label="Убрать позицию"
            >
              <Trash2 className="h-4 w-4" />
            </button>
            <button
              type="button"
              onClick={(e) => { e.stopPropagation(); setOpen(false); onEditItem(item); }}
              className="text-steel-500 hover:text-accent-400 group-hover/item:text-accent-400"
              aria-label="Изменить количество"
            >
              <Pencil className="h-4 w-4" />
            </button>
          </div>
        </div>
      ))}
      <div className="px-4 py-3">
        <button
          type="button"
          onClick={() => { ymGoal('cart_add_more'); setOpen(false); onAddMore(); }}
          className="flex min-h-[100px] w-full items-center justify-center gap-2 rounded-lg border border-dashed border-white/20 text-sm font-medium text-steel-400 transition hover:border-accent-500/60 hover:bg-accent-500/[0.06] hover:text-accent-300 focus-visible:border-accent-500/60 focus-visible:outline-none"
        >
          <Plus className="h-5 w-5" />
          Добавить товар
        </button>
      </div>
    </>
  );

  const footer = (
    <div className="border-t border-white/[0.08] px-4 py-3.5">
      <div className="flex items-center justify-between text-sm">
        <span className="text-steel-400">Итого предварительно</span>
        <span className="font-display text-lg font-semibold text-white">~{formatRub(total)} ₽</span>
      </div>
      <div className="mt-0.5 text-[11px] text-steel-500">
        {(totalWeight / 1000).toLocaleString('ru-RU', { maximumFractionDigits: 3 })} т суммарно ·
        окончательная цена — после согласования с менеджером
      </div>
      <button
        type="button"
        onClick={() => { ymGoal('checkout_click'); setOpen(false); onCheckout(); }}
        className="btn-primary mt-3 w-full"
      >
        Перейти к оформлению
        <ArrowRight className="h-4 w-4" />
      </button>
    </div>
  );

  return (
    <>
      {/* Десктоп: узкий язычок у правого края + выезжающая панель с затемнением */}
      <div className="hidden lg:block">
        <button
          type="button"
          onClick={() => setOpen(true)}
          aria-label={`Ваш заказ: ${items.length} ${itemsWord}, ~${formatRub(total)} ₽`}
          className={`fixed right-0 top-1/2 z-40 flex w-[64px] -translate-y-1/2 flex-col items-center gap-3 rounded-l-xl bg-accent-500 px-2 py-4 text-white shadow-glow-orange transition-all duration-300 hover:bg-accent-400 ${
            bump ? 'scale-110' : ''
          } ${open ? 'pointer-events-none opacity-0' : 'opacity-100'}`}
        >
          <span className="relative">
            <ShoppingCart className="h-6 w-6" />
            <span className="absolute -right-2.5 -top-2.5 grid h-5 min-w-[20px] place-items-center rounded-full bg-white px-1 text-[11px] font-bold leading-none text-accent-600">
              {items.length}
            </span>
          </span>
          <span className="text-[12px] font-semibold uppercase tracking-[0.18em] [writing-mode:vertical-rl] rotate-180">
            Ваш заказ
          </span>
          <span className="text-center font-mono text-[11px] font-semibold leading-tight">
            ~{formatRub(total)}
            <span className="block opacity-80">₽</span>
          </span>
        </button>

        <div
          onClick={() => setOpen(false)}
          className={`fixed inset-0 z-40 bg-black/50 transition-opacity duration-300 ${
            open ? 'opacity-100' : 'pointer-events-none opacity-0'
          }`}
          aria-hidden="true"
        />

        <aside
          role="dialog"
          aria-label="Ваш заказ"
          aria-hidden={!open}
          className={`fixed inset-y-0 right-0 z-50 flex w-[400px] max-w-full flex-col border-l border-white/10 bg-graphite-900 shadow-panel transition-transform duration-300 ease-out ${
            open ? 'translate-x-0' : 'pointer-events-none translate-x-full'
          }`}
        >
          {header}
          <div className="flex-1 overflow-y-auto divide-y divide-white/[0.06]">{list}</div>
          {footer}
        </aside>
      </div>

      {/* Мобильная версия: прежний вид, открытие только по нажатию */}
      <div className="fixed right-5 top-1/2 z-40 flex -translate-y-1/2 flex-col items-end gap-2 lg:hidden">
        <div
          className={`grid w-[calc(100vw-2.5rem)] max-w-sm overflow-hidden transition-[grid-template-rows,opacity] duration-300 ease-out ${
            open ? 'grid-rows-[1fr] opacity-100' : 'pointer-events-none grid-rows-[0fr] opacity-0'
          }`}
        >
          <div className="min-h-0">
            <div className="glass-panel overflow-hidden bg-graphite-900/95 shadow-panel">
              {header}
              <div className="max-h-[40vh] overflow-y-auto divide-y divide-white/[0.06]">{list}</div>
              {footer}
            </div>
          </div>
        </div>

        <button
          type="button"
          onClick={() => setOpen((v) => !v)}
          className="glass-panel flex items-center gap-3 bg-graphite-900/95 px-4 py-3 shadow-panel transition hover:border-accent-500/40"
        >
          <span className="grid h-9 w-9 shrink-0 place-items-center rounded-full bg-accent-500/15 ring-1 ring-accent-500/30">
            <ShoppingCart className="h-4 w-4 text-accent-400" />
          </span>
          <span className="text-left">
            <span className="block text-sm font-semibold text-white">
              {items.length} {itemsWord} · ~{formatRub(total)} ₽
            </span>
            <span className="block text-[11px] text-steel-500">предварительно</span>
          </span>
          {open ? (
            <ChevronDown className="h-4 w-4 shrink-0 text-steel-400" />
          ) : (
            <ChevronUp className="h-4 w-4 shrink-0 text-steel-400" />
          )}
        </button>
      </div>
    </>
  );
}
