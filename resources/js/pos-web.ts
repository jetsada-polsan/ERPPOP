import { createApp } from 'vue';
import PosCartPanel from './pos/PosCartPanel.vue';

const mount = document.getElementById('pos-vue-cart-panel');

if (mount) {
    createApp(PosCartPanel).mount(mount);
}
