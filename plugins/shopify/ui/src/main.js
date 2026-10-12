// Entry point of the Shopify plugin's runtime UI bundle.
//
// Inventoros imports the built file (dist/plugin.js) and calls the default
// export with an SDK scoped to this plugin. "vue" is not bundled: the build
// maps it to window.Inventoros.Vue, the app's own copy.
import SettingsPage from './SettingsPage.vue';
import ShopifySettingsCard from './ShopifySettingsCard.vue';
import en from './locales/en.json';
import it from './locales/it.json';

export default function setup(plugin) {
    plugin.i18n?.addMessages('en', en);
    plugin.i18n?.addMessages('it', it);

    // Supplies Inertia::render('Plugin::shopify/Settings') for register_page('plg.shopify.settings', ...).
    plugin.registerPage('Settings', SettingsPage);

    // Implements add_page_component('settings.index', 'sections', [... 'component' => 'ShopifySettingsCard']).
    plugin.registerComponent('ShopifySettingsCard', ShopifySettingsCard);
}
