import { ref, watch } from 'vue';

const KEY = 'dfc_theme';

/** 'light' | 'dark' | 'system' */
export const theme = ref(localStorage.getItem(KEY) ?? 'system');

const media = window.matchMedia('(prefers-color-scheme: dark)');

function resolved() {
    return theme.value === 'system' ? (media.matches ? 'dark' : 'light') : theme.value;
}

export function applyTheme() {
    const mode = resolved();

    // Tailwind's dark variant keys off the class; `data-theme` is what the
    // artifact/host chrome reads. Both are set so neither can disagree.
    document.documentElement.classList.toggle('dark', mode === 'dark');
    document.documentElement.dataset.theme = mode;

    // Tells the browser to render form controls and scrollbars in the right
    // scheme — without it, native widgets stay light on a dark page.
    document.documentElement.style.colorScheme = mode;
}

export function setTheme(next) {
    theme.value = next;
    localStorage.setItem(KEY, next);
    applyTheme();
}

export function cycleTheme() {
    setTheme({ light: 'dark', dark: 'system', system: 'light' }[theme.value] ?? 'light');
}

/** Following the OS only makes sense while the user has chosen 'system'. */
media.addEventListener('change', () => {
    if (theme.value === 'system') applyTheme();
});

watch(theme, applyTheme);

export function useTheme() {
    return { theme, setTheme, cycleTheme, applyTheme, resolved };
}
