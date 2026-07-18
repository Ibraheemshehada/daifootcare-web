<script setup>
import { computed } from 'vue';
import { useRoute } from 'vue-router';
import AppLayout from '@/layouts/AppLayout.vue';

const route = useRoute();

// Login and 404 render standalone; everything else gets the sidebar shell.
const useBlankLayout = computed(() => route.meta.layout === 'blank');
</script>

<template>
    <UApp>
        <RouterView v-if="useBlankLayout" v-slot="{ Component }">
            <Transition name="fade" mode="out-in">
                <component :is="Component" />
            </Transition>
        </RouterView>

        <AppLayout v-else />
    </UApp>
</template>

<style>
.fade-enter-active,
.fade-leave-active {
    transition: opacity 0.18s ease;
}

.fade-enter-from,
.fade-leave-to {
    opacity: 0;
}

/* Respect a user's reduced-motion preference — page transitions are decorative. */
@media (prefers-reduced-motion: reduce) {
    .fade-enter-active,
    .fade-leave-active {
        transition: none;
    }
}
</style>
