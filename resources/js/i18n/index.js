import { createI18n } from 'vue-i18n';
import en from './locales/en.json';
import ar from './locales/ar.json';

const LOCALE_KEY = 'dfc_locale';

export const SUPPORTED_LOCALES = [
    { code: 'en', name: 'English', dir: 'ltr' },
    { code: 'ar', name: 'العربية', dir: 'rtl' },
];

export function storedLocale() {
    const saved = localStorage.getItem(LOCALE_KEY);
    return SUPPORTED_LOCALES.some((l) => l.code === saved) ? saved : 'en';
}

export function applyLocale(code) {
    const locale = SUPPORTED_LOCALES.find((l) => l.code === code) ?? SUPPORTED_LOCALES[0];

    i18n.global.locale.value = locale.code;
    localStorage.setItem(LOCALE_KEY, locale.code);

    document.documentElement.lang = locale.code;
    document.documentElement.dir = locale.dir;
}

// Without these, `d(date, 'short')` silently renders the raw ISO string.
const datetimeFormats = {
    short: {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    },
    date: { year: 'numeric', month: 'short', day: 'numeric' },
};

const i18n = createI18n({
    legacy: false,
    locale: storedLocale(),
    fallbackLocale: 'en',
    messages: { en, ar },
    datetimeFormats: {
        en: datetimeFormats,
        // Gregorian calendar with Arabic month names — the app's clinical records are
        // Gregorian, so an Islamic-calendar rendering here would not match the device.
        ar: datetimeFormats,
    },
});

// Apply direction on load, not only on change — a reload in Arabic must come back RTL.
applyLocale(storedLocale());

export default i18n;
