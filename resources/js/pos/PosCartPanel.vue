<script setup lang="ts">
import { computed, onMounted, onUnmounted, reactive } from 'vue';

type CartItem = {
    id: number | string;
    name_th?: string;
    sku_code?: string;
    unit_name?: string;
    unit_factor?: number;
    qty: number;
    unit_price: number;
    discount_value?: number;
    discount_type?: 'baht' | 'percent';
    is_free_gift?: boolean;
    promo_name?: string;
    matched_barcode?: string;
};

type Promotion = {
    promo_type?: string;
    product_id?: number | string;
    min_qty?: number;
    discount_type?: 'baht' | 'percent';
    discount_value?: number;
    bundle_price?: number;
};

type PosState = {
    canSell: boolean;
    cart: CartItem[];
    promotions: Promotion[];
    billDiscountValue: number;
    billDiscountType: 'baht' | 'percent';
    vatMode: 'included' | 'excluded';
    vatRate: number;
    appliedCard: null | {
        min_amount?: number;
        discount_type?: 'baht' | 'percent';
        discount_value?: number;
        max_discount_amount?: number;
    };
    redeemPoints: number;
    pointValueBaht: number;
    memberPoints: number;
}

const emptyState = (): PosState => ({
    canSell: false,
    cart: [],
    promotions: [],
    billDiscountValue: 0,
    billDiscountType: 'baht',
    vatMode: 'included',
    vatRate: 7,
    appliedCard: null,
    redeemPoints: 0,
    pointValueBaht: 0,
    memberPoints: 0,
});

const state = reactive<PosState>(emptyState());

function roundMoney(value: number): number {
    return Math.round((Number(value) || 0) * 100) / 100;
}

function number(value: unknown): number {
    return Number(value) || 0;
}

function money(value: unknown): string {
    return number(value).toLocaleString('th-TH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function eventValue(event: Event): string {
    return (event.target as HTMLInputElement | HTMLSelectElement).value;
}

const saleLines = computed(() => state.cart.filter((item) => !item.is_free_gift));
const totalQty = computed(() => roundMoney(saleLines.value.reduce((sum, item) => sum + Math.max(0, number(item.qty)), 0)));
const subtotal = computed(() => roundMoney(saleLines.value.reduce((sum, item) => sum + number(item.qty) * number(item.unit_price), 0)));

function itemDiscount(item: CartItem): number {
    const base = Math.max(0, number(item.qty) * number(item.unit_price));
    const value = Math.max(0, number(item.discount_value));
    const discount = item.discount_type === 'percent' ? base * value / 100 : value;
    return roundMoney(Math.min(base, discount));
}

function lineNet(item: CartItem): number {
    return roundMoney(Math.max(0, number(item.qty) * number(item.unit_price) - itemDiscount(item)));
}

const itemDiscountTotal = computed(() => roundMoney(saleLines.value.reduce((sum, item) => sum + itemDiscount(item), 0)));

const promoDiscountTotal = computed(() => {
    let total = 0;
    for (const promo of state.promotions) {
        if (!['discount', 'bundle_price'].includes(String(promo.promo_type))) continue;
        const lines = saleLines.value.filter((item) => String(item.id) === String(promo.product_id));
        const qty = lines.reduce((sum, item) => sum + number(item.qty), 0);
        const minQty = Math.max(1, number(promo.min_qty));
        const sets = Math.floor(qty / minQty);
        if (!sets || !lines.length) continue;
        const unitPrice = number(lines[0].unit_price);
        if (promo.promo_type === 'bundle_price') {
            total += Math.max(0, sets * (minQty * unitPrice - number(promo.bundle_price)));
            continue;
        }
        const base = sets * minQty * unitPrice;
        const discount = promo.discount_type === 'percent'
            ? base * number(promo.discount_value) / 100
            : number(promo.discount_value) * sets;
        total += Math.min(base, Math.max(0, discount));
    }
    return roundMoney(total);
});

const billDiscount = computed(() => {
    const base = Math.max(0, subtotal.value - itemDiscountTotal.value);
    const value = Math.max(0, number(state.billDiscountValue));
    const discount = state.billDiscountType === 'percent' ? base * value / 100 : value;
    return roundMoney(Math.min(base, discount));
});

const cardDiscount = computed(() => {
    const card = state.appliedCard;
    if (!card) return 0;
    const base = Math.max(0, subtotal.value - itemDiscountTotal.value - billDiscount.value);
    if (card.min_amount && base < number(card.min_amount)) return 0;
    let discount = card.discount_type === 'percent'
        ? base * number(card.discount_value) / 100
        : number(card.discount_value);
    if (card.max_discount_amount) discount = Math.min(discount, number(card.max_discount_amount));
    return roundMoney(Math.min(base, discount));
});

const pointsDiscount = computed(() => {
    if (state.pointValueBaht <= 0) return 0;
    const base = Math.max(0, subtotal.value - itemDiscountTotal.value - billDiscount.value - cardDiscount.value);
    return roundMoney(Math.min(base, Math.max(0, Math.min(number(state.redeemPoints), number(state.memberPoints))) * number(state.pointValueBaht)));
});

const totalDiscount = computed(() => roundMoney(itemDiscountTotal.value + billDiscount.value + cardDiscount.value + pointsDiscount.value + promoDiscountTotal.value));
const netBeforeVat = computed(() => roundMoney(Math.max(0, subtotal.value - totalDiscount.value)));
const vat = computed(() => state.vatMode === 'excluded'
    ? roundMoney(netBeforeVat.value * number(state.vatRate) / 100)
    : roundMoney(netBeforeVat.value * number(state.vatRate) / (100 + number(state.vatRate))));
const beforeVat = computed(() => roundMoney(netBeforeVat.value - (state.vatMode === 'included' ? vat.value : 0)));
const total = computed(() => state.vatMode === 'excluded' ? roundMoney(netBeforeVat.value + vat.value) : netBeforeVat.value);

function syncState(event: Event): void {
    const incoming = (event as CustomEvent<PosState>).detail;
    if (!incoming) return;
    Object.assign(state, incoming);
}

function action(type: string, payload: Record<string, unknown> = {}): void {
    window.dispatchEvent(new CustomEvent('pos-vue-action', { detail: { type, ...payload } }));
}

function setItemField(index: number, field: string, value: unknown): void {
    action('set-item-field', { index, field, value: field === 'discount_type' ? value : number(value) });
}

onMounted(() => {
    const initial = (window as Window & { __POS_VUE_STATE__?: PosState }).__POS_VUE_STATE__;
    if (initial) Object.assign(state, initial);
    window.addEventListener('pos-vue-state', syncState);
});

onUnmounted(() => window.removeEventListener('pos-vue-state', syncState));
</script>

<template>
    <section class="pos-vue-cart" aria-label="ตะกร้าสินค้า Vue">
        <div v-if="!state.cart.length" class="cart-empty">
            <i class="bi bi-bag"></i>
            <span>ยังไม่มีสินค้า</span>
        </div>
        <template v-else>
            <div class="cart-list-head"><span>#</span><span>สินค้า</span><span>จำนวน</span><span class="text-end">รวม</span><span></span></div>
            <div v-for="(item, index) in state.cart" :key="item.uid || `${item.id}-${index}`" class="cart-item" :class="{ 'gift-line': item.is_free_gift }">
                <div class="cart-line-no">{{ index + 1 }}</div>
                <div class="cart-product-cell">
                    <div class="cart-item-name">{{ item.name_th }}</div>
                    <div class="cart-item-sku">
                        <span>{{ item.sku_code }}</span>
                        <span v-if="!item.is_free_gift"> • ฿{{ money(item.unit_price) }}/หน่วย</span>
                        <span v-if="item.unit_name"> • {{ item.unit_name }}{{ item.unit_factor && item.unit_factor !== 1 ? ` x${money(item.unit_factor)}` : '' }}</span>
                        <span v-if="item.is_free_gift" class="gift-label"> • ของแถม: {{ item.promo_name || '' }}</span>
                    </div>
                </div>
                <div class="cart-qty-cell">
                    <template v-if="!item.is_free_gift">
                        <button class="qty-btn" type="button" @click="action('change-qty', { index, delta: -1 })"><i class="bi bi-dash"></i></button>
                        <input class="qty-input" type="number" min="0.001" step="0.001" :value="item.qty" @change="setItemField(index, 'qty', eventValue($event))">
                        <button class="qty-btn" type="button" @click="action('change-qty', { index, delta: 1 })"><i class="bi bi-plus"></i></button>
                    </template>
                    <div v-else class="qty-display">{{ money(item.qty) }}</div>
                </div>
                <div class="cart-item-price">{{ item.is_free_gift ? 'ฟรี' : `฿${money(lineNet(item))}` }}</div>
                <button v-if="!item.is_free_gift" class="trash-btn" type="button" @click="action('remove-item', { index })"><i class="bi bi-trash3"></i></button>
                <span v-else></span>
                <div v-if="!item.is_free_gift" class="cart-line-tools">
                    <span class="tool-label">ราคา</span>
                    <input class="price-input" type="number" min="0" step="0.01" :value="item.unit_price" @change="setItemField(index, 'unit_price', eventValue($event))">
                    <span class="tool-label">ลด</span>
                    <div class="discount-cell">
                        <input class="discount-input" type="number" min="0" step="0.01" :value="item.discount_value || 0" @change="setItemField(index, 'discount_value', eventValue($event))">
                        <select class="discount-type" :value="item.discount_type || 'baht'" @change="setItemField(index, 'discount_type', eventValue($event))">
                            <option value="baht">฿</option><option value="percent">%</option>
                        </select>
                    </div>
                </div>
            </div>
        </template>

        <div class="pos-cart-footer">
            <div class="bill-tools">
                <div><label>ส่วนลดท้ายบิล</label><div class="discount-cell"><input class="discount-input" type="number" min="0" step="0.01" :value="state.billDiscountValue" @change="action('set-field', { field: 'billDiscountValue', value: eventValue($event) })"><select class="discount-type" :value="state.billDiscountType" @change="action('set-field', { field: 'billDiscountType', value: eventValue($event) })"><option value="baht">฿</option><option value="percent">%</option></select></div></div>
                <div><label>VAT</label><div class="vat-toggle"><button type="button" :class="{ active: state.vatMode === 'included' }" @click="action('set-field', { field: 'vatMode', value: 'included' })">รวม</button><button type="button" :class="{ active: state.vatMode === 'excluded' }" @click="action('set-field', { field: 'vatMode', value: 'excluded' })">แยก</button></div></div>
            </div>
            <div class="cart-totals">
                <div class="total-row"><span>รายการ</span><span>{{ state.cart.length }} รายการ</span></div>
                <div class="total-row"><span>จำนวนรวม</span><span>{{ money(totalQty) }} ชิ้น</span></div>
                <div class="total-row muted"><span>ยอดก่อนลด</span><span>฿{{ money(subtotal) }}</span></div>
                <div v-if="promoDiscountTotal > 0" class="total-row discount"><span>ส่วนลดแคมเปญ</span><span>-฿{{ money(promoDiscountTotal) }}</span></div>
                <div class="total-row discount"><span>ส่วนลดรวม</span><span>-฿{{ money(totalDiscount) }}</span></div>
                <div class="total-row muted"><span>ฐานก่อน VAT</span><span>฿{{ money(beforeVat) }}</span></div>
                <div class="total-row muted"><span>VAT {{ state.vatRate }}%</span><span>฿{{ money(vat) }}</span></div>
                <div class="total-row grand"><span>ยอดสุทธิ</span><span class="val">฿{{ money(total) }}</span></div>
            </div>
        </div>
        <button v-if="state.canSell" class="vue-cart-actions" type="button" :disabled="!state.cart.length" @click="action('open-payment')"><i class="bi bi-cash-coin"></i> คิดเงิน / ชำระเงิน</button>
    </section>
</template>

<style scoped>
.pos-vue-cart { height: 100%; display: flex; flex-direction: column; min-height: 0; }
.pos-vue-cart > .cart-empty { flex: 1; }
.gift-label { color: #059669; font-weight: 900; }
.vue-cart-actions { margin: 8px 10px 10px; min-height: 48px; border: 0; border-radius: 12px; background: linear-gradient(135deg,#10b981,#047857); color: #fff; font: inherit; font-size: 16px; font-weight: 900; cursor: pointer; box-shadow: 0 4px 16px rgba(16,185,129,.25); }
.vue-cart-actions:disabled { opacity: .4; cursor: not-allowed; }
</style>
