export const DAYS = [
    {value: 1, short: "Lun", long: "Lundi"},
    {value: 2, short: "Mar", long: "Mardi"},
    {value: 3, short: "Mer", long: "Mercredi"},
    {value: 4, short: "Jeu", long: "Jeudi"},
    {value: 5, short: "Ven", long: "Vendredi"},
    {value: 6, short: "Sam", long: "Samedi"},
    {value: 7, short: "Dim", long: "Dimanche"},
];

export const DATE_FORMATS = ["YYYYMMDD", "YYYY-MM-DD", "YYMMDD", "DDMMYYYY", "DD-MM-YYYY", "DDMMYY", "DD.MM.YYYY", "DD_MM_YYYY"];

export function emptyJob() {
    return {
        id: "",
        name: "",
        enabled: true,
        source: {
            type: "ftp",
            host: "",
            port: 0,
            username: "",
            password: "",
            private_key: "",
            has_password: false,
            has_private_key: false,
            encryption: "none",
            passive: true,
            verify_tls: true,
            remote_dir: "",
            url: "",
        },
        file: {
            pattern: "",
            select: "newest",
            date_enabled: false,
            date_format: "YYYYMMDD",
            date_offset: 0,
        },
        target: {
            directory: "",
            rename_enabled: false,
            rename_pattern: "",
            on_exists: "overwrite",
            playlist_id: null,
        },
        schedule: {
            slots: [{days: [1, 2, 3, 4, 5], time: "06:00"}],
            retry_interval: 15,
            retry_count: 8,
            skip_unchanged: true,
        },
    };
}
