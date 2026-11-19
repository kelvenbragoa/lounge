<script setup>
import { ref } from 'vue';

import AppMenuItem from './AppMenuItem.vue';

const model = ref([
    {
        label: 'Cartões',
        items: [
            { label: 'Balcão', icon: 'pi pi-fw pi-id-card', to: '/cards/desk' },
            { label: 'Turno', icon: 'pi pi-fw pi-wallet', to: '/cards/shift' },
            { label: 'Cartões', icon: 'pi pi-fw pi-list', to: '/cards/list' },
            { label: 'Dashboard', icon: 'pi pi-fw pi-chart-bar', to: '/cards/dashboard' }
        ]
    }
]);
</script>

<template>
    <ul class="layout-menu">
        <template v-for="(item, i) in model" :key="item">
            <app-menu-item v-if="!item.separator" :item="item" :index="i"></app-menu-item>
            <li v-if="item.separator" class="menu-separator"></li>
        </template>
    </ul>
</template>
