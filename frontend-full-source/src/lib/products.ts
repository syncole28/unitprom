export type Category = {
  slug: string;
  title: string;
  shortDesc: string;
  steel: string;
  gostMain: string;
  priceFrom: number;
  inStock: number;
  image: string;
  parentSlug: string | null;
  filterLabel?: string | null;
  filterRanges?: string | null;
  filter1Label?: string | null;
  filter1Type?: string | null;
  filter1Values?: string | null;
  filter2Label?: string | null;
  filter2Type?: string | null;
  filter2Values?: string | null;
};

export const categories: Category[] = [
  {
    slug: 'armatura',
    title: 'Арматура',
    shortDesc: 'А500С, А3, А1 — ребристая и гладкая',
    steel: 'Ст3, 25Г2С, 35ГС',
    gostMain: 'ГОСТ 34028-2016',
    priceFrom: 48500,
    inStock: 2840,
    image:
      'https://images.pexels.com/photos/2219024/pexels-photo-2219024.jpeg?auto=compress&cs=tinysrgb&w=1200',
  },
  {
    slug: 'truby',
    title: 'Трубы профильные',
    shortDesc: 'Квадратные, прямоугольные, круглые',
    steel: 'Ст3сп/пс',
    gostMain: 'ГОСТ 30245-2003',
    priceFrom: 62100,
    inStock: 1980,
    image:
      'https://images.pexels.com/photos/162568/oil-pump-jack-sunset-clouds-silhouette-162568.jpeg?auto=compress&cs=tinysrgb&w=1200',
  },
  {
    slug: 'list',
    title: 'Листовой металл',
    shortDesc: 'Горячекатаный, холоднокатаный, рифлёный',
    steel: 'Ст3, 09Г2С',
    gostMain: 'ГОСТ 19903-2015',
    priceFrom: 58400,
    inStock: 3120,
    image:
      'https://images.pexels.com/photos/162539/architecture-building-amsterdam-blue-sky-162539.jpeg?auto=compress&cs=tinysrgb&w=1200',
  },
  {
    slug: 'ugolok',
    title: 'Уголок',
    shortDesc: 'Равнополочный и неравнополочный',
    steel: 'Ст3сп5',
    gostMain: 'ГОСТ 8509-93',
    priceFrom: 56700,
    inStock: 1640,
    image:
      'https://images.pexels.com/photos/2244746/pexels-photo-2244746.jpeg?auto=compress&cs=tinysrgb&w=1200',
  },
  {
    slug: 'shveller',
    title: 'Швеллер',
    shortDesc: 'Горячекатаный, гнутый',
    steel: 'Ст3, 09Г2С',
    gostMain: 'ГОСТ 8240-97',
    priceFrom: 64900,
    inStock: 1210,
    image:
      'https://images.pexels.com/photos/162553/keys-workshop-mechanic-tools-162553.jpeg?auto=compress&cs=tinysrgb&w=1200',
  },
  {
    slug: 'balka',
    title: 'Балка',
    shortDesc: 'Двутавровая, нормальная, широкополочная',
    steel: 'Ст3сп, 09Г2С',
    gostMain: 'ГОСТ Р 57837-2017',
    priceFrom: 71200,
    inStock: 860,
    image:
      'https://images.pexels.com/photos/259915/pexels-photo-259915.jpeg?auto=compress&cs=tinysrgb&w=1200',
  },
  {
    slug: 'krug',
    title: 'Круг стальной',
    shortDesc: 'Конструкционный, инструментальный',
    steel: 'Ст3, 40Х, 45',
    gostMain: 'ГОСТ 2590-2006',
    priceFrom: 54200,
    inStock: 1980,
    image:
      'https://images.pexels.com/photos/1108101/pexels-photo-1108101.jpeg?auto=compress&cs=tinysrgb&w=1200',
  },
  {
    slug: 'kvadrat',
    title: 'Квадрат',
    shortDesc: 'Калиброванный, горячекатаный',
    steel: 'Ст3, 09Г2С',
    gostMain: 'ГОСТ 2591-2006',
    priceFrom: 57600,
    inStock: 740,
    image:
      'https://images.pexels.com/photos/236748/pexels-photo-236748.jpeg?auto=compress&cs=tinysrgb&w=1200',
  },
];

// Длина товара (считает сервер, см. api/lib/adjustments.php): fixed — продаётся штуками;
// nemer — немерная: штуки и тонны, 1 шт считается по расчётной длине (lengthM, обычно 11,5 м),
// цены за штуку в каталоге нет; coil — бухта/рулон и none — длина не задана: метры и тонны.
export type LengthKind = 'fixed' | 'nemer' | 'coil' | 'none';

export type CalcOption = {
  id: string;
  label: string;
  weightPerMeter: number;
  // Длина одной штуки, м (у немерной — расчётная); null — штуками не продаётся
  lengthM: number | null;
  lengthKind?: LengthKind;
  // «немерная 9–12 м», «в бухте» — показывается вместо цены за штуку
  lengthText?: string;
  // Лист: weightPerMeter — вес 1 м², считаем в м² вместо м
  isSheet?: boolean;
  pricePerTon: number;
  paramValue?: number | null;
  inStock?: number;
  param1Value?: string | null;
  param2Value?: string | null;
  // Только для листов: площадь одного листа, м². У листов weightPerMeter — это вес 1 м².
  sheetAreaM2?: number | null;
};

// Стандартная ширина листа, если размеры не удалось взять из названия.
export const DEFAULT_SHEET_WIDTH_M = 1.5;

// Размеры листа из названия вида «Лист Г/К 3, 1.25м 2.5м, ...» → ширина и длина в метрах.
export function parseSheetSize(label: string): { widthM: number; lengthM: number } | null {
  const m = label.match(/(\d+(?:[.,]\d+)?)\s*м\s*[x×хX*]?\s*(\d+(?:[.,]\d+)?)\s*м/);
  if (!m) return null;
  const a = parseFloat(m[1].replace(',', '.'));
  const b = parseFloat(m[2].replace(',', '.'));
  if (!(a > 0) || !(b > 0)) return null;
  return { widthM: Math.min(a, b), lengthM: Math.max(a, b) };
}

// Вес одной штуки: для листа — вес 1 м² × площадь листа, для остального — вес метра × длина.
// null — у позиции нет штуки (немерная, в бухте, длина не задана).
export function pieceWeightKg(p: CalcOption): number | null {
  if (p.sheetAreaM2) return p.weightPerMeter * p.sheetAreaM2;
  if (p.lengthM) return p.weightPerMeter * p.lengthM;
  return null;
}

export const calcCatalog: Record<string, CalcOption[]> = {
  armatura: [
    { id: 'a500-10', label: 'Арматура А500С d10', weightPerMeter: 0.617, pricePerTon: 48500 },
    { id: 'a500-12', label: 'Арматура А500С d12', weightPerMeter: 0.888, pricePerTon: 48700 },
    { id: 'a500-14', label: 'Арматура А500С d14', weightPerMeter: 1.208, pricePerTon: 48900 },
    { id: 'a500-16', label: 'Арматура А500С d16', weightPerMeter: 1.578, pricePerTon: 49100 },
    { id: 'a500-20', label: 'Арматура А500С d20', weightPerMeter: 2.466, pricePerTon: 49400 },
  ],
  truby: [
    { id: 'pr-40x40x3', label: 'Труба профильная 40×40×3', weightPerMeter: 3.39, pricePerTon: 62100 },
    { id: 'pr-60x40x3', label: 'Труба профильная 60×40×3', weightPerMeter: 4.39, pricePerTon: 62500 },
    { id: 'pr-80x80x4', label: 'Труба профильная 80×80×4', weightPerMeter: 9.02, pricePerTon: 63200 },
    { id: 'pr-100x100x5', label: 'Труба профильная 100×100×5', weightPerMeter: 14.3, pricePerTon: 64000 },
  ],
  list: [
    { id: 'l-3', label: 'Лист г/к 3 мм (1.5×6 м)', weightPerMeter: 211.95, pricePerTon: 58400 },
    { id: 'l-4', label: 'Лист г/к 4 мм (1.5×6 м)', weightPerMeter: 282.6, pricePerTon: 58900 },
    { id: 'l-6', label: 'Лист г/к 6 мм (1.5×6 м)', weightPerMeter: 423.9, pricePerTon: 59400 },
    { id: 'l-8', label: 'Лист г/к 8 мм (1.5×6 м)', weightPerMeter: 565.2, pricePerTon: 60100 },
  ],
  ugolok: [
    { id: 'u-50x5', label: 'Уголок 50×50×5', weightPerMeter: 3.77, pricePerTon: 56700 },
    { id: 'u-63x5', label: 'Уголок 63×63×5', weightPerMeter: 4.81, pricePerTon: 57100 },
    { id: 'u-75x6', label: 'Уголок 75×75×6', weightPerMeter: 6.89, pricePerTon: 57500 },
    { id: 'u-100x7', label: 'Уголок 100×100×7', weightPerMeter: 10.79, pricePerTon: 58200 },
  ],
  shveller: [
    { id: 'sh-10', label: 'Швеллер 10П', weightPerMeter: 8.59, pricePerTon: 64900 },
    { id: 'sh-12', label: 'Швеллер 12П', weightPerMeter: 10.4, pricePerTon: 65300 },
    { id: 'sh-16', label: 'Швеллер 16П', weightPerMeter: 14.2, pricePerTon: 66000 },
    { id: 'sh-20', label: 'Швеллер 20П', weightPerMeter: 18.4, pricePerTon: 66700 },
  ],
  balka: [
    { id: 'b-20', label: 'Балка двутавр 20Б1', weightPerMeter: 21.3, pricePerTon: 71200 },
    { id: 'b-25', label: 'Балка двутавр 25Б1', weightPerMeter: 25.7, pricePerTon: 72000 },
    { id: 'b-30', label: 'Балка двутавр 30Б1', weightPerMeter: 32.9, pricePerTon: 72800 },
  ],
  krug: [
    { id: 'k-12', label: 'Круг d12', weightPerMeter: 0.888, pricePerTon: 54200 },
    { id: 'k-20', label: 'Круг d20', weightPerMeter: 2.466, pricePerTon: 54600 },
    { id: 'k-30', label: 'Круг d30', weightPerMeter: 5.549, pricePerTon: 55100 },
  ],
  kvadrat: [
    { id: 'kv-12', label: 'Квадрат 12×12', weightPerMeter: 1.13, pricePerTon: 57600 },
    { id: 'kv-20', label: 'Квадрат 20×20', weightPerMeter: 3.14, pricePerTon: 58000 },
    { id: 'kv-30', label: 'Квадрат 30×30', weightPerMeter: 7.07, pricePerTon: 58500 },
  ],
};
