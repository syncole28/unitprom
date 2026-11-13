import type { CartItem } from '../App';

function fmt(n: number, digits = 3): string {
  return n.toLocaleString('ru-RU', { maximumFractionDigits: digits });
}

// «3 шт × 6 м» / «2 шт × 3,125 м²» или, для позиций без штуки, «25 м».
export function itemQtyText(item: Pick<CartItem, 'qty' | 'lengthM' | 'areaM2' | 'amount' | 'amountUnit' | 'nemer'>): string {
  if (item.qty > 0 && item.nemer) return `${item.qty} шт × ≈${fmt(item.lengthM)} м`;
  if (item.qty > 0) {
    return `${item.qty} шт × ${item.areaM2 ? `${fmt(item.areaM2)} м²` : `${fmt(item.lengthM)} м`}`;
  }
  return `${fmt(item.amount ?? 0)} ${item.amountUnit ?? 'м'}`;
}

// Для Метрики: цена и количество «за единицу». У позиций без штуки — одна единица на всю сумму.
export function itemEcommerce(item: Pick<CartItem, 'qty' | 'totalPrice'>): { price: number; quantity: number } {
  if (item.qty > 0) return { price: Math.round(item.totalPrice / item.qty), quantity: item.qty };
  return { price: Math.round(item.totalPrice), quantity: 1 };
}

// «1 заготовка / 2 заготовки / 5 заготовок»
export function plural(n: number, one: string, few: string, many: string): string {
  const a = Math.abs(n) % 100;
  const b = a % 10;
  if (a > 10 && a < 20) return many;
  if (b === 1) return one;
  if (b >= 2 && b <= 4) return few;
  return many;
}
