// AzuraCast Show Downloader – navigation.
// Served as-is (no build) and injected into every AzuraCast panel page by the plugin.
// 1. Registers the page /station/<id>/show-downloader inside AzuraCast's own Vue router,
//    reusing the station layout (header, sidebar) so the page looks native.
// 2. Adds "Téléchargements auto" to the station sidebar, just after "Media".

const ITEM_ID = "show-downloader-menu-item";
const ERROR_ID = "show-downloader-error";
const ROUTE_NAME = "show-downloader:index";
const SUFFIX = "/show-downloader/assets/navigation.js";
const ICON_PATH = "M19.35 10.04A7.49 7.49 0 0 0 12 4C9.11 4 6.6 5.64 5.35 8.04A5.994 5.994 0 0 0 0 14c0 3.31 "
    + "2.69 6 6 6h13c2.76 0 5-2.24 5-5 0-2.64-2.05-4.78-4.65-4.96M17 13l-5 5-5-5h3V9h4v4z";

function stationPath(pathname, basePath) {
    const prefix = `${basePath}/station/`;
    if (!pathname.startsWith(prefix)) {
        return null;
    }
    const match = pathname.slice(prefix.length).match(/^([1-9]\d*)(?:\/|$)/);
    return match ? {id: match[1], path: `${prefix}${match[1]}`} : null;
}

function mount(document, window, basePath, version) {
    let router = null;
    let failed = false;
    let pageModule = null;
    let frame = null;

    function showError(error) {
        window.console.error("Show Downloader:", error);
        if (document.getElementById(ERROR_ID)) return;
        const alert = document.createElement("div");
        alert.id = ERROR_ID;
        alert.className = "alert alert-danger m-3";
        alert.setAttribute("role", "alert");
        alert.textContent = "Impossible de charger « Téléchargements auto ». Rechargez la page ; si le problème persiste, "
            + "relancez bin/setup.sh dans le dossier du plugin.";
        (document.getElementById("content") || document.body).prepend(alert);
    }

    function loadPage() {
        if (!pageModule) {
            const assets = `${basePath}/show-downloader/assets`;
            const css = new Promise((resolve, reject) => {
                if (document.getElementById("show-downloader-css")) return resolve();
                const link = document.createElement("link");
                link.id = "show-downloader-css";
                link.rel = "stylesheet";
                link.href = `${assets}/app.css?v=${version}`;
                link.onload = resolve;
                link.onerror = () => reject(new Error("Feuille de style introuvable"));
                document.head.append(link);
            });
            pageModule = Promise.all([import(`${assets}/app.js?v=${version}`), css])
                .then(([module]) => module.ShowDownloaderPage)
                .catch((error) => {
                    pageModule = null;
                    showError(error);
                    throw error;
                });
        }
        return pageModule;
    }

    function connectRouter() {
        if (router || failed) return;
        const app = document.getElementById("dashboard")?.__vue_app__;
        const nativeRouter = app?.config?.globalProperties?.$router;
        if (!nativeRouter) return;
        try {
            const layout = nativeRouter.getRoutes().find((route) =>
                route.path === "/station/:station_id" && route.children?.some((child) => child.path === ""));
            if (!layout?.components?.default) {
                throw new Error("La mise en page des stations d'AzuraCast est introuvable.");
            }
            if (!nativeRouter.hasRoute(ROUTE_NAME)) {
                nativeRouter.addRoute({
                    path: "/station/:station_id/show-downloader",
                    component: layout.components.default,
                    children: [{
                        path: "",
                        name: ROUTE_NAME,
                        component: loadPage,
                        props: (route) => ({
                            base: `${basePath}/show-downloader/${route.params.station_id}`,
                        }),
                    }],
                });
            }
            router = nativeRouter;
            router.afterEach(() => schedule());
            router.isReady().then(() => {
                const route = router.currentRoute.value;
                // A direct link may have been resolved before the route existed.
                if (/^\/station\/[1-9]\d*\/show-downloader\/?$/.test(route.path) && route.name !== ROUTE_NAME) {
                    router.replace(route.fullPath).catch(showError);
                }
            });
        } catch (error) {
            failed = true;
            showError(error);
        }
    }

    function refresh() {
        frame = null;
        connectRouter();

        const station = stationPath(window.location.pathname, basePath);
        const mediaMenu = document.querySelector("#sidebar-submenu-media");
        const mediaItem = mediaMenu?.closest("li.nav-item");
        const existing = document.getElementById(ITEM_ID);

        // Only shown to users who can see the station's "Music Files" (Media permission).
        const canManage = station && mediaMenu && [...mediaMenu.querySelectorAll("a[href]")].some((link) => {
            const url = new URL(link.href, window.location.href);
            return url.pathname === `${station.path}/files`;
        });
        if (!canManage || !mediaItem) {
            existing?.remove();
            return;
        }

        // Other plugins (e.g. Sweepers) also insert their entry right after "Media". To avoid two
        // scripts moving their entries back and forth forever, ours goes after every entry that
        // another plugin has added after "Media" (plugin entries are the <li> that have an id).
        let anchor = mediaItem;
        for (let next = mediaItem.nextElementSibling; next?.matches("li.nav-item[id]"); next = next.nextElementSibling) {
            if (next.id !== ITEM_ID) anchor = next;
        }

        const href = `${station.path}/show-downloader`;
        const active = window.location.pathname.replace(/\/$/, "") === href;
        if (existing && existing.dataset.signature === `${href}|${active}`) {
            if (existing.previousElementSibling !== anchor) anchor.after(existing);
            return;
        }
        existing?.remove();

        const item = document.createElement("li");
        item.id = ITEM_ID;
        item.className = "nav-item";
        item.dataset.signature = `${href}|${active}`;

        const link = document.createElement("a");
        link.className = active ? "nav-link active" : "nav-link";
        link.href = href;
        if (active) link.setAttribute("aria-current", "page");

        const svg = document.createElementNS("http://www.w3.org/2000/svg", "svg");
        svg.setAttribute("class", "navdrawer-nav-icon");
        svg.setAttribute("viewBox", "0 0 24 24");
        svg.setAttribute("width", "1em");
        svg.setAttribute("height", "1em");
        svg.setAttribute("aria-hidden", "true");
        const path = document.createElementNS("http://www.w3.org/2000/svg", "path");
        path.setAttribute("fill", "currentColor");
        path.setAttribute("d", ICON_PATH);
        svg.append(path);

        const label = document.createElement("span");
        label.className = "might-overflow";
        label.textContent = "Téléchargements auto";

        link.append(svg, label);
        item.append(link);
        anchor.after(item);
    }

    function schedule() {
        if (frame === null) {
            frame = window.requestAnimationFrame(refresh);
        }
    }

    // Delegated click handler: keeps working even if the menu entry is rebuilt.
    document.addEventListener("click", (event) => {
        const link = event.target.closest?.(`#${ITEM_ID} a[href]`);
        if (!router || !link || event.defaultPrevented || event.button > 0
            || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
            return;
        }
        const url = new URL(link.href, window.location.href);
        if (url.origin !== window.location.origin) return;
        event.preventDefault();
        document.getElementById(ERROR_ID)?.remove();
        router.push(url.pathname.slice(basePath.length)).catch(showError);
    });

    new window.MutationObserver(schedule).observe(document.body, {childList: true, subtree: true});
    document.addEventListener("vue-ready", schedule);
    window.addEventListener("popstate", schedule);
    schedule();
}

if (typeof window !== "undefined" && !window.showDownloaderNavigation) {
    const url = new URL(import.meta.url);
    if (url.pathname.endsWith(SUFFIX)) {
        window.showDownloaderNavigation = true;
        mount(document, window, url.pathname.slice(0, -SUFFIX.length), url.searchParams.get("v") || "dev");
    }
}
