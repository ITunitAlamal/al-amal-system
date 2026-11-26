import '../css/aims.css';
import { createApp, h } from 'vue';
import { createInertiaApp } from '@inertiajs/vue3';
import NavigationLoading from './Components/NavigationLoading.vue';

createInertiaApp({
    resolve: (name) => {
        const pages = import.meta.glob('./Pages/**/*.vue');

        const page = pages[`./Pages/${name}.vue`];

        if (!page) {
            throw new Error(`Page not found: ${name}`);
        }

        return page();
    },

    setup({ el, App, props, plugin }) {
        createApp({
            render: () => h(NavigationLoading, null, { default: () => h(App, props) }),
        })
            .use(plugin)
            .mount(el);
    },

    progress: false,
    title: (title) => `${title} | معهد الأمل للأيتام`,
});
