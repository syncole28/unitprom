declare global {
  interface Window {
    ym?: (id: number, action: string, goal: string) => void;
    YM_COUNTER_ID?: number;
    dataLayer?: unknown[];
  }
}

export function ymGoal(goal: string) {
  if (typeof window.ym === 'function' && window.YM_COUNTER_ID) {
    window.ym(window.YM_COUNTER_ID, 'reachGoal', goal);
  }
}

export type EcommerceProduct = {
  id: string;
  name: string;
  price: number;
  quantity: number;
};

function pushDataLayer(payload: Record<string, unknown>) {
  if (typeof window === 'undefined') return;
  window.dataLayer = window.dataLayer || [];
  window.dataLayer.push(payload);
}

// Товар добавлен в заказ — вызывать вместе с ymGoal('cart_add')
export function ymEcommerceAdd(product: EcommerceProduct) {
  pushDataLayer({
    ecommerce: {
      currencyCode: 'RUB',
      add: { products: [product] },
    },
  });
}

// Заявка отправлена — аналог «покупки» для Метрики, со всеми позициями и суммой
export function ymEcommercePurchase(orderId: string, products: EcommerceProduct[], revenue: number) {
  pushDataLayer({
    ecommerce: {
      currencyCode: 'RUB',
      purchase: {
        actionField: { id: orderId, revenue },
        products,
      },
    },
  });
}
