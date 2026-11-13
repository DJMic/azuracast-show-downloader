import { createApp, h } from "vue";
import App from "./App.vue";
import "./style.css";

// Route component used by AzuraCast's router. It only renders an empty host element;
// the plugin's own Vue app (bundled with its own Vue copy) is mounted inside it.
export const ShowDownloaderPage = {
    name: "ShowDownloaderPage",
    props: ["base"],
    data: () => ({unmountApp: null}),
    watch: {
        base() {
            this.mountApp();
        },
    },
    mounted() {
        this.mountApp();
    },
    beforeUnmount() {
        this.unmountApp?.();
    },
    methods: {
        mountApp() {
            this.unmountApp?.();
            const app = createApp(App, {base: this.base});
            app.mount(this.$el);
            this.unmountApp = () => app.unmount();
        },
    },
    render() {
        return h("div", {class: "show-downloader"});
    },
};
