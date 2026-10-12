// t() for the plugin's own strings (plugins.shopify.*), falling back to the
// English text when the app has no plugin i18n (apiVersion 1).
import en from './locales/en.json';

const plugin = () => window.Inventoros?.plugin?.('shopify');

function fallback(key, params) {
    const text = key.split('.').reduce((node, part) => (node && typeof node === 'object' ? node[part] : undefined), en);
    if (typeof text !== 'string') {
        return key;
    }

    return text.replace(/\{(\w+)\}/g, (match, name) => (params && name in params ? String(params[name]) : match));
}

export function t(key, params = {}) {
    const i18n = plugin()?.i18n;

    return i18n?.te?.(key) ? i18n.t(key, params) : fallback(key, params);
}
