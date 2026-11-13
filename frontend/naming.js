// Mirror of src/Service/Naming.php, used for the live preview in the editor.

export function formatDate(format, date) {
    const pad = (n) => String(n).padStart(2, "0");
    return format.replace(/YYYY|YY|MM|M|DD|D/g, (token) => ({
        YYYY: String(date.getFullYear()),
        YY: String(date.getFullYear()).slice(-2),
        MM: pad(date.getMonth() + 1),
        M: String(date.getMonth() + 1),
        DD: pad(date.getDate()),
        D: String(date.getDate()),
    })[token]);
}

export function render(pattern, vars, date, dateFormat) {
    let result = pattern.replace(/\{date(?::([^}]*))?\}/g, (_, fmt) => formatDate(fmt || dateFormat, date));
    for (const [key, value] of Object.entries(vars)) {
        result = result.split(`{${key}}`).join(String(value));
    }
    return result;
}

export function splitName(name) {
    const dot = name.lastIndexOf(".");
    if (dot <= 0 || dot === name.length - 1) return [name, ""];
    return [name.slice(0, dot), name.slice(dot + 1).toLowerCase()];
}

export function sanitize(name) {
    const clean = name.replace(/[/\\]/g, "-").replace(/[\u0000-\u001f\u007f]/g, "").replace(/^[\s.]+|[\s.]+$/g, "");
    return clean === "" ? "emission" : clean.slice(0, 200);
}

export function targetName(original, job, index, date) {
    const target = job.target;
    if (!target.rename_enabled || target.rename_pattern.trim() === "") return sanitize(original);
    const [base, ext] = splitName(original);
    let name = sanitize(render(target.rename_pattern, {original: base, ext, show: job.name, n: index}, date,
        job.file.date_format || "YYYYMMDD"));
    const [, newExt] = splitName(name);
    const looksLikeExt = /^[a-z0-9]{2,5}$/.test(newExt) && !/^\d+$/.test(newExt);
    if (ext && (!newExt || !looksLikeExt)) name += `.${ext}`;
    return name;
}

export function nameDate(job, reference = new Date()) {
    const date = new Date(reference.getFullYear(), reference.getMonth(), reference.getDate());
    if (job.file.date_enabled) date.setDate(date.getDate() + Number(job.file.date_offset || 0));
    return date;
}

export function targetPath(job, original, index, date) {
    const dir = (job.target.directory || "").replace(/^\/+|\/+$/g, "");
    return (dir ? `${dir}/` : "") + targetName(original, job, index, date);
}
