/**
 * Chart tokens.
 *
 * Deliberately separate from the UI brand colour. The dashboard's cyan
 * (#0e7490) measures chroma 0.094 — below the data-viz chroma floor, so as a
 * plotted mark it reads as grey. Brand chrome and data marks are different jobs:
 * chrome wears the brand, data wears a validated series ramp.
 *
 * Values are the reference data-viz palette, validated for both surfaces.
 */

/** Single-series line/area colour. Most charts here plot one measure. */
export const SERIES = {
    light: '#2a78d6',
    dark: '#3987e5',
};

/** Second series, when two measures genuinely share one axis and scale. */
export const SERIES_2 = {
    light: '#008300',
    dark: '#008300',
};

/**
 * Ordinal ramp for ordered bands (SUS excellent→poor).
 *
 * Ordered categories are sequential, not categorical: one hue, light→dark. The
 * lightest step is 250 rather than 100 because an ordinal mark must still clear
 * 2:1 against the surface, unlike a continuous heatmap cell.
 */
export const ORDINAL = {
    light: ['#184f95', '#2a78d6', '#5598e7', '#86b6ef'],
    dark: ['#cde2fb', '#9ec5f4', '#6da7ec', '#3987e5'],
};

/**
 * Reserved status colours — never reused as a series.
 *
 * On the light surface `warning` and `serious` sit below 3:1 by design, so a
 * status colour is always shipped with a text label, never colour alone.
 */
export const STATUS = {
    good: '#0ca30c',
    warning: '#fab219',
    serious: '#ec835a',
    critical: '#d03b3b',
};

/** Recessive chart furniture. */
export const AXIS = {
    light: { grid: '#e2e8f0', text: '#475569', surface: '#ffffff' },
    dark: { grid: '#1e293b', text: '#94a3b8', surface: '#0f172a' },
};

export function isDark() {
    return document.documentElement.classList.contains('dark');
}
