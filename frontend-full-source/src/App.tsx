import { useEffect, useState } from 'react';
import { Header } from './components/Header';
import { Hero } from './components/Hero';
import { Catalog } from './components/Catalog';
import { Advantages } from './components/Advantages';
import { WhyUs } from './components/WhyUs';
import { Calculator } from './components/Calculator';
import { QuickCalcModal } from './components/QuickCalcModal';
import type { QuickCalcTarget } from './components/QuickCalcModal';
import { Certificates } from './components/Certificates';
import { Reviews } from './components/Reviews';
import { Geography } from './components/Geography';
import { ApplicationForm } from './components/ApplicationForm';
import { OrderWidget } from './components/OrderWidget';
import { Footer } from './components/Footer';
import { CookieNotice } from './components/CookieNotice';
import { useCatalog } from './lib/useCatalog';
import type { CalcOption } from './lib/products';
import { ymGoal } from './lib/ym';

export type CartItem = {
  id: string;
  weightKg: number;
  totalPrice: number;
  productLabel: string;
  qty: number;
  lengthM: number;
  // Единицы, в которых позицию добавили (для редактирования); в старых сохранённых корзинах может отсутствовать.
  unit?: 'pcs' | 'm' | 't';
  // Только для листов: площадь одного листа, м².
  areaM2?: number;
  // Позиции без штуки (немерные, в бухте, без длины): qty = 0, количество — в метрах (у листа в м²).
  amount?: number;
  amountUnit?: 'м' | 'м²';
  // Немерная: штуки по расчётной длине (lengthM ≈ 11,5 м)
  nemer?: boolean;
  // Резка в размер (стоимость резки считает менеджер)
  cut?: CutInfo;
};

export type CutInfo = {
  // Длина заготовки, м (для длинномера)
  lengthM?: number;
  // Для листов: размеры заготовок текстом
  sizes?: string;
  note?: string;
  // Резка пополам (длинномер — на 2 части по половине длины, лист — поперёк)
  half?: true;
  // Готовая строка для корзины, заявки и уведомления
  text: string;
};

type CalcResult = Omit<CartItem, 'id'>;

function makeId(): string {
  if (typeof crypto !== 'undefined' && 'randomUUID' in crypto) return crypto.randomUUID();
  return `${Date.now()}-${Math.random().toString(16).slice(2)}`;
}

// Корзина хранится в браузере, чтобы не пропадать при обновлении страницы.
const CART_STORAGE_KEY = 'unitprom_cart_v1';
const CART_TTL_MS = 7 * 24 * 60 * 60 * 1000;

function isCartItem(v: unknown): v is CartItem {
  if (!v || typeof v !== 'object') return false;
  const i = v as Record<string, unknown>;
  return (
    typeof i.id === 'string' &&
    typeof i.productLabel === 'string' &&
    typeof i.weightKg === 'number' &&
    typeof i.totalPrice === 'number' &&
    typeof i.qty === 'number' &&
    typeof i.lengthM === 'number'
  );
}

function loadCart(): CartItem[] {
  try {
    const raw = localStorage.getItem(CART_STORAGE_KEY);
    if (!raw) return [];
    const data = JSON.parse(raw) as { savedAt?: number; items?: unknown };
    if (typeof data.savedAt !== 'number' || Date.now() - data.savedAt > CART_TTL_MS) return [];
    if (!Array.isArray(data.items)) return [];
    return data.items.filter(isCartItem).map((i) => {
      if (i.cut === undefined || (i.cut && typeof i.cut.text === 'string')) return i;
      return { ...i, cut: undefined };
    });
  } catch {
    return [];
  }
}

function saveCart(items: CartItem[]) {
  try {
    if (items.length === 0) localStorage.removeItem(CART_STORAGE_KEY);
    else localStorage.setItem(CART_STORAGE_KEY, JSON.stringify({ savedAt: Date.now(), items }));
  } catch {
    // Хранилище недоступно (приватный режим и т.п.) — корзина просто живёт до перезагрузки.
  }
}

function App() {
  const [cartItems, setCartItems] = useState<CartItem[]>(loadCart);
  useEffect(() => saveCart(cartItems), [cartItems]);
  const [quickCalc, setQuickCalc] = useState<QuickCalcTarget | null>(null);
  const catalog = useCatalog();

  function scrollTo(id: string) {
    requestAnimationFrame(() => {
      document.getElementById(id)?.scrollIntoView({ behavior: 'smooth', block: 'start' });
    });
  }

  // Каталог — основной способ оформить заказ: клик «Рассчитать» на позиции
  // открывает мини-калькулятор прямо поверх каталога, без перехода куда-либо.
  function handleOpenQuickCalc(_leafSlug: string, product: CalcOption) {
    setQuickCalc({ mode: 'add', product });
  }

  function handleEditCartItem(item: CartItem) {
    setQuickCalc({ mode: 'edit', item });
  }

  function handleCloseQuickCalc() {
    setQuickCalc(null);
  }

  // Дешёвый вариант рекламы на конкретную позицию: unit-prom.ru/?product=<id>
  // сразу открывает мини-калькулятор; ?category=<leafSlug> — на первую позицию категории.
  useEffect(() => {
    if (catalog.loading) return;
    const params = new URLSearchParams(window.location.search);
    const productParam = params.get('product');
    const categoryParam = params.get('category');

    if (productParam) {
      for (const items of Object.values(catalog.calcCatalog)) {
        const found = items.find((p) => p.id === productParam);
        if (found) {
          scrollTo('catalog');
          setQuickCalc({ mode: 'add', product: found });
          ymGoal('ad_product_landing');
          return;
        }
      }
    }
    if (categoryParam && catalog.calcCatalog[categoryParam]?.length) {
      scrollTo('catalog');
      setQuickCalc({ mode: 'add', product: catalog.calcCatalog[categoryParam][0] });
      ymGoal('ad_product_landing');
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [catalog.loading]);

  function handleAddToCart(payload: CalcResult) {
    setCartItems((prev) => [...prev, { ...payload, id: makeId() }]);
  }

  function handleFinalize(payload: CalcResult) {
    setCartItems((prev) => [...prev, { ...payload, id: makeId() }]);
    scrollTo('contact');
  }

  function handleSaveCartItem(id: string, payload: CalcResult) {
    setCartItems((prev) => prev.map((i) => (i.id === id ? { ...payload, id } : i)));
  }

  // «Добавить товар» в корзине и в форме заявки — назад в каталог.
  function handleAddMore() {
    scrollTo('catalog');
  }

  function handleGoToCheckout() {
    scrollTo('contact');
  }

  function handleRemoveItem(id: string) {
    setCartItems((prev) => prev.filter((i) => i.id !== id));
  }

  function handleClearCart() {
    setCartItems([]);
  }

  return (
    <div className="relative min-h-screen overflow-x-hidden bg-graphite-950">
      <Header />
      <main>
        <Hero featured={catalog.featured} loading={catalog.loading} />
        <Catalog
          topCategories={catalog.topCategories}
          subcategories={catalog.subcategories}
          products={catalog.calcCatalog}
          loading={catalog.loading}
          onProductSelect={handleOpenQuickCalc}
        />
        <Advantages />
        <WhyUs />
        <Calculator
          topCategories={catalog.topCategories}
          subcategories={catalog.subcategories}
          calcCatalog={catalog.calcCatalog}
          loading={catalog.loading}
          onAddToCart={handleAddToCart}
          onOrder={handleFinalize}
        />
        <Certificates />
        <Reviews />
        <Geography />
        <ApplicationForm
          cartItems={cartItems}
          onRemoveItem={handleRemoveItem}
          onEditItem={handleEditCartItem}
          onAddMore={handleAddMore}
          onClear={handleClearCart}
        />
      </main>
      <Footer />
      <CookieNotice />
      <OrderWidget
        items={cartItems}
        onRemoveItem={handleRemoveItem}
        onEditItem={handleEditCartItem}
        onAddMore={handleAddMore}
        onCheckout={handleGoToCheckout}
      />
      {quickCalc && (
        <QuickCalcModal
          target={quickCalc}
          onClose={handleCloseQuickCalc}
          onAddToCart={(payload) => { handleAddToCart(payload); handleCloseQuickCalc(); }}
          onOrder={(payload) => { handleFinalize(payload); handleCloseQuickCalc(); }}
          onSave={(payload) => {
            if (quickCalc.mode === 'edit') handleSaveCartItem(quickCalc.item.id, payload);
            handleCloseQuickCalc();
          }}
        />
      )}
    </div>
  );
}

export default App;
