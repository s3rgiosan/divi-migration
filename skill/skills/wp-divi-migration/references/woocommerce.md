# WooCommerce

## Products built with Divi

Divi's product builder replaces a product's `post_content` with a layout made of `et_pb_wc_*` modules (title, images, price, add to cart, tabs, related products), and keeps the real description in `_et_pb_old_content`. Converting that layout would put the product page into the description.

When a product's builder content holds only sections, rows, columns and `et_pb_wc_*` modules, restore `_et_pb_old_content` as the description (after the usual backup) and let the theme's product template render the page. `wp divi-migration convert` does this for such products, and keeps the description as classic content: products open in WooCommerce's classic product screen, not the block editor. A product layout with other modules (a text block, an image) needs a decision: move that content into the description, or into a template for those products.

## Templates

Extensions that hook into the classic templates (bookings and appointments forms, add-ons, checkout fields) keep working when the block theme renders WooCommerce pages through the classic templates: `woocommerce/legacy-template` with `single-product`, `archive-product` (shop, product category, product search). The markup then matches what Divi showed, and the child theme's WooCommerce CSS ports as it is. Move to WooCommerce's block templates only when every extension supports them.

Cart, checkout and account pages hold shortcodes (`[woocommerce_cart]`, `[woocommerce_checkout]`, `[woocommerce_my_account]`), sometimes inside a Divi text module. Keep the shortcodes: checkout fields added through classic hooks work only there.

## Cart in the header

Divi's header cart (icon and "N items") becomes the Mini Cart block; size its button to fit the top bar.

WooCommerce hooks blocks into header parts through the Block Hooks API. When the site switches from a classic theme to a block theme, WooCommerce sets the option `woocommerce_hooked_blocks_version`, and the hooks start. On each part whose area is `header`, WooCommerce then inserts `woocommerce/customer-account` and `woocommerce/mini-cart` after every Navigation block. It skips a block type that the part already holds. The new header then shows an account icon after each menu, and a mini cart after each menu when the part holds no Mini Cart block.

Add the hooked blocks to `metadata.ignoredHookedBlocks` on each Navigation block in a header part. Core does not insert a hooked block that the anchor block lists there:

```html
<!-- wp:navigation {"metadata":{"ignoredHookedBlocks":["woocommerce/customer-account","woocommerce/mini-cart"]}} /-->
```

Keep the other attributes of the block. Check the header in the parity loop: a part from `markup` output needs the same metadata on each of its Navigation blocks.

## Product grid on phones

Divi and WooCommerce use different product columns on small screens (Divi `style.dev.css`, "Responsive Styles"):

| Width | Divi | WooCommerce `woocommerce-smallscreen.css` |
|---|---|---|
| 768px to 980px | 3 per row (`width: 28.333%`) | the desktop columns, 2 per row at 768px |
| 480px to 767px | 2 per row (`width: 45.25%`) | 2 per row (`width: 48%`) |
| 479px and below | 1 per row (`width: 100%`) | 2 per row (`width: 48%`) |

WooCommerce loads `woocommerce-smallscreen.css` for `max-width: 768px` (filter `woocommerce_style_smallscreen_breakpoint`). On a phone, the shop and the related products show two columns, and the page gets much shorter than on the Divi site. Reproduce Divi's columns in the theme stylesheet. `:nth-child(n)` raises the specificity above WooCommerce's `width: 48%` rule:

```css
@media (max-width: 479px) {
	.woocommerce ul.products[class*="columns-"] li.product:nth-child(n) {
		float: none;
		margin-right: 0;
		width: 100%;
	}
}
```

## REST access

The Mini Cart and cart blocks use the Store API (`/wc/store/`). A security plugin or must-use plugin that requires login for REST requests breaks them for visitors: allowlist the Store API, and any form plugin routes (Contact Form 7 submits to `/contact-form-7/`).
