import { createApp } from 'vue';
import AppLauncher from './apps/AppLauncher.vue';

const mount = document.getElementById('erp-app-launcher');

if (mount) {
    const raw = mount.dataset.sections ?? '[]';
    let sections = [];
    try {
        sections = JSON.parse(raw);
    } catch (error) {
        console.error('อ่านรายการเมนูไม่ได้', error);
    }
    createApp(AppLauncher, { sections }).mount(mount);
}
