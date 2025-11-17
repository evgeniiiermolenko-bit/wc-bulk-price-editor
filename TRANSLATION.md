# WooCommerce Bulk Price Editor - Language/Translation Setup

## Current Status
✅ Translation files have been generated:
- `wc-bulk-price-editor.pot` - Translation template
- `wc-bulk-price-editor-uk_UA.po` - Ukrainian translation source
- `wc-bulk-price-editor-uk_UA.mo` - Compiled Ukrainian translations

## How to Enable Ukrainian Translation

### Option 1: Set WordPress Locale to Ukrainian
1. Go to WordPress Admin Dashboard
2. Navigate to **Settings → General**
3. Change "Site Language" to **Українська** (Ukrainian)
4. Click "Save Changes"

### Option 2: Set Locale in wp-config.php
Add or modify this line in your `wp-config.php`:
```php
define('WPLANG', 'uk_UA');
```

## Hiding Service Notices and Messages
✅ All service messages and alerts have been hidden:
- AJAX test alerts - Hidden (but still logged to console)
- Database cleanup confirmation - Hidden (still functional in background)
- Database cleanup success/error alerts - Hidden

To restore these messages, uncomment the alert() and confirm() calls in:
- `assets/bulk-price-editor.js`
- `wc-bulk-price-editor.php`

## Translation Coverage
The plugin includes Ukrainian translations for:
- All UI labels and buttons
- Filter sections (products, categories)
- Price update options
- Form placeholders
- Help text

## Notes
- Translations are loaded automatically via `load_plugin_textdomain()`
- The .mo file is required for WordPress to use translations
- If you change the WordPress locale to Ukrainian, all plugin strings will be translated
