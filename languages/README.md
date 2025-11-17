# Translation Files

This directory contains translation files for WooCommerce Bulk Price Editor.

## Files

- `wc-bulk-price-editor.pot` - Translation template (base for all translations)
- `wc-bulk-price-editor-uk_UA.po` - Ukrainian translation (Portable Object)
- `wc-bulk-price-editor-uk_UA.mo` - Ukrainian translation compiled (Machine Object)

## Compiling .po to .mo

To compile the Ukrainian translation from .po to .mo format, use:

```bash
msgfmt -o languages/wc-bulk-price-editor-uk_UA.mo languages/wc-bulk-price-editor-uk_UA.po
```

Or use an online tool:
- https://localise.biz/webapp/converter
- https://poedit.net/ (GUI tool - recommended)

The .mo file is the binary format that WordPress uses for translations.

## Creating New Translations

1. Copy `wc-bulk-price-editor.pot` to `wc-bulk-price-editor-[locale].po`
2. Edit the .po file with your translations
3. Compile to .mo using the command above
4. Test in WordPress by changing site language

Example for German (de_DE):
- Copy: `wc-bulk-price-editor.pot` → `wc-bulk-price-editor-de_DE.po`
- Translate strings in the file
- Compile: `msgfmt -o languages/wc-bulk-price-editor-de_DE.mo languages/wc-bulk-price-editor-de_DE.po`

## Current Languages

- ✅ English (default - in main plugin files)
- ✅ Ukrainian (uk_UA) - Українська
