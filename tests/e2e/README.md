# End-to-end tests

Run against a wp-env instance:

```bash
npm run env:start
npx wp-env run tests-cli wp theme activate storefront
npm run test:e2e
```

Two projects run every spec: `desktop` at 1440×900 and `mobile` at 375×812.

## Per-theme visual checks

The acceptance criteria for M04 name Storefront, Astra, Kadence, Blocksy, Divi
and Elementor Hello. Screenshots are per project, so a theme pass is a matter of
activating the theme and re-running:

```bash
npx wp-env run tests-cli wp theme install astra --activate
npm run test:e2e -- --update-snapshots
```

Selectors are plugin-owned `data-pph-*` attributes only. A spec that reaches for
a theme's class names will pass on the theme it was written against and fail on
every other one, which is the opposite of what these tests are for.
