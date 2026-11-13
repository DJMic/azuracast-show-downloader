import { test } from "node:test";
import assert from "node:assert/strict";
import { formatDate, render, targetName, targetPath } from "../frontend/naming.js";

const d = new Date(2026, 2, 5);
const job = (target, file = {}) => ({
    name: "Ma Matinale",
    file: { date_enabled: true, date_format: "YYYYMMDD", date_offset: 0, ...file },
    target: { directory: "Emissions/Matinale", rename_enabled: true, rename_pattern: "", ...target },
});

test("date tokens (same results as Naming.php)", () => {
    assert.equal(formatDate("YYYYMMDD", d), "20260305");
    assert.equal(formatDate("DD-MM-YY", d), "05-03-26");
    assert.equal(formatDate("D.M.YYYY", d), "5.3.2026");
    assert.equal(render("Show_{date:DD.MM.YYYY}", {}, d, "YYYYMMDD"), "Show_05.03.2026");
});

test("renaming", () => {
    assert.equal(targetName("emission_0305.mp3", job({ rename_pattern: "Matinale" }), 1, d), "Matinale.mp3");
    assert.equal(targetName("a.mp3", job({ rename_pattern: "{show} {date:YYYY-MM-DD}" }), 1, d), "Ma Matinale 2026-03-05.mp3");
    assert.equal(targetName("a.mp3", job({ rename_pattern: "Show_{date:DD.MM.YYYY}" }), 1, d), "Show_05.03.2026.mp3");
    assert.equal(targetName("a.wav", job({ rename_pattern: "{original}_{n}.{ext}" }), 2, d), "a_2.wav");
    assert.equal(targetName("Abc 1.MP3", job({ rename_enabled: false }), 1, d), "Abc 1.MP3");
    assert.equal(targetPath(job({ rename_pattern: "X" }), "y.mp3", 1, d), "Emissions/Matinale/X.mp3");
});
