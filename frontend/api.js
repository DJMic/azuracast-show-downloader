export function createApi(base) {
    let csrf = "";

    async function request(path, {method = "GET", body = undefined, query = undefined} = {}) {
        const url = new URL(`${base}${path}`, window.location.origin);
        for (const [key, value] of Object.entries(query || {})) {
            if (value !== undefined && value !== null && value !== "") url.searchParams.set(key, value);
        }
        const headers = {"Accept": "application/json"};
        if (method !== "GET") {
            headers["Content-Type"] = "application/json";
            headers["X-CSRF-Token"] = csrf;
        }
        let response;
        try {
            response = await fetch(url, {
                method,
                headers,
                credentials: "same-origin",
                body: body === undefined ? undefined : JSON.stringify(body),
            });
        } catch {
            throw new Error("Le serveur AzuraCast ne répond pas. Vérifiez votre connexion.");
        }
        const data = await response.json().catch(() => ({}));
        if (!response.ok) {
            if (response.status === 403) {
                throw new Error("Accès refusé : la permission « Gérer les médias » de la station est nécessaire.");
            }
            throw new Error(data.message || `Erreur du serveur (HTTP ${response.status}).`);
        }
        if (data.csrf) csrf = data.csrf;
        return data;
    }

    // Downloads the export through fetch so that an error is shown instead of being saved as a file.
    async function exportFile(withSecrets) {
        const url = new URL(`${base}/export`, window.location.origin);
        url.searchParams.set("secrets", withSecrets ? "1" : "0");
        const response = await fetch(url, {credentials: "same-origin"});
        if (!response.ok) {
            const data = await response.json().catch(() => ({}));
            throw new Error(data.message || `Erreur du serveur (HTTP ${response.status}).`);
        }
        const disposition = response.headers.get("Content-Disposition") || "";
        const name = disposition.match(/filename="([^"]+)"/)?.[1] || "telechargements-auto.json";
        const blob = await response.blob();
        const link = document.createElement("a");
        link.href = URL.createObjectURL(blob);
        link.download = name;
        document.body.append(link);
        link.click();
        link.remove();
        setTimeout(() => URL.revokeObjectURL(link.href), 10000);
        return name;
    }

    return {
        state: () => request("/state"),
        folders: (dir) => request("/folders", {query: {dir}}),
        history: (job) => request("/history", {query: {job}}),
        save: (job) => request("/jobs", {method: "POST", body: job}),
        remove: (id) => request(`/jobs/${encodeURIComponent(id)}/delete`, {method: "POST", body: {}}),
        run: (id) => request(`/jobs/${encodeURIComponent(id)}/run`, {method: "POST", body: {}}),
        test: (job) => request("/test", {method: "POST", body: job}),
        clearHistory: () => request("/history/clear", {method: "POST", body: {}}),
        importPreview: (data, choices) => request("/import/preview", {method: "POST", body: {data, choices}}),
        importJobs: (data, choices, createDirectories) => request("/import", {
            method: "POST",
            body: {data, choices, create_directories: createDirectories},
        }),
        exportFile,
    };
}
