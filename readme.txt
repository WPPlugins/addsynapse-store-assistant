=== AddSynapse – AI Website & Store Assistant for WooCommerce ===
Contributors: sakkoulas
Tags: chatgpt, claude, ai agent, woocommerce ai, product management
Requires at least: 6.9
Tested up to: 7.1
Requires PHP: 7.4
WC requires at least: 8.0
WC tested up to: 11.1
Stable tag: 1.3.5
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Run your WooCommerce store or WordPress site from Claude or ChatGPT, or chat in wp-admin with your own AI key. Every change is a draft you approve.

== Description ==

**Talk to your store. Approve what changes.**

AddSynapse turns your WordPress site or WooCommerce store into something you can simply talk to. Ask "which products are low on stock?", "what were my best sellers last month?", "lower every price in Summer Dresses by 10%" or "write better SEO titles for my bags" — and get real answers from your own data, plus ready-made drafts of the changes you asked for.

Nothing changes on your site until you press **Approve**. And every approved change can be put back exactly as it was with **Undo**.

Use it two ways — or both:

= 1. From Claude, with no API key =

Connect your site once and use it from the Claude you already pay for, on claude.ai or the Claude apps.

* **No API key, no setup files.** A short wizard on **AddSynapse → Home** checks your site, then you connect it from Claude and confirm the connection on this plugin's own page.
* **Drafts you approve inside the chat.** Price changes, sales, stock, SEO titles and meta descriptions, coupons, page and post texts, menus, featured images: Claude prepares a draft and shows it as a card with the exact before and after values. Press **Approve** in the card and it is applied; until then nothing on your site changes.
* **Exact Undo.** Every approved change can be undone from the card or from your AddSynapse account, restoring the previous values — and only if nothing else changed them in the meantime.
* **All your stores in one place.** Connect several stores and sites to the same free AddSynapse account and ask Claude about any of them.
* **A log on your own site.** Every write is listed in **Settings → Approval log**, and you can disconnect at any time with **Disconnect AddSynapse** on **AddSynapse → Home**.
* **Locked down by design.** The connection uses a key this plugin creates when you confirm, valid only on its own store routes (no users, plugins, settings or other REST routes), and every write needs a single-use approval signed at the moment you pressed Approve. It is not a WordPress Application Password: like WooCommerce's own REST API keys, it is a separate key that only this plugin accepts, and you revoke it here.

**ChatGPT (beta).** The same connection works from ChatGPT as a custom MCP connector (developer mode, depending on your ChatGPT plan); the wizard shows the steps. See the FAQ.

This way needs a free AddSynapse account (created during the connection). It includes a daily allowance of actions; larger allowances are available on paid plans at addsynapse.com. See **External services** below for exactly what is sent.

= 2. A chat inside wp-admin, with your own AI key =

Paste an Anthropic (Claude) or OpenAI (GPT) API key in **Settings** and the chat on **AddSynapse → Chat** works with no account and no plan. You pay your AI provider directly for what you use; Settings shows an estimate of your usage and cost. The key is stored encrypted on your site (or in `wp-config.php`) and is never sent to the browser or to AddSynapse.

The chat edits one product at a time:

* **Product descriptions:** write, rewrite, shorten or translate a product's name, short description and description.
* **Product SEO:** a new SEO title, meta description and focus keyword in Rank Math or Yoast SEO, with lengths checked.
* **Product images:** drop or paste a photo into the chat and make it the main image or add it to the gallery.
* **Prices and stock:** price and sale price with dates, and the stock quantity.
* **Categories, tags and brand:** assign the product to existing ones.

Each request costs as little as possible on your key: the assistant sends only the instructions and tools that question needs. Slash commands (for example `/report low_stock` or `/price id=123 -10%`), previews, Approve, Undo and voice input work even before you add a key.

= WooCommerce reports in plain words =

Search products by name, SKU, category, tag, brand, attribute, price or stock, and ask for low stock (each product's own threshold), out of stock, products missing images, descriptions or prices, best sellers, sales by day, week or month, recent orders and top customers — customers only as anonymous references; no names or emails leave your site.

= Works on plain WordPress too =

No WooCommerce? From Claude you can edit pages and posts (title, excerpt, text, publish or unpublish), menus, featured images and SEO titles and descriptions — each a draft you approve, with exact Undo. The wp-admin chat reads pages, posts, menus, media and SEO too.

= Every change is a draft a person approves =

The assistant only reads data and prepares drafts. A change is applied only when a logged-in user approves it in wp-admin, or when you press Approve on the draft card in Claude. The assistant never creates, edits or deletes user accounts, never changes passwords and never runs code or SQL: it can only use a fixed set of checked operations.

= Store Assistant Pro (separate add-on) =

**Store Assistant Pro** is a separate, paid add-on by AddSynapse for the wp-admin chat: bulk product edits and CSV imports, SEO at scale, pages, menus and design by chat, catalog management (categories, attributes, variations), coupons and orders, a full change history, and an AI included so you need no key of your own. Details: https://addsynapse.com/store-assistant. Pro is not included in this plugin and this plugin does not check for it: everything described above works without it. The plugin links to the add-on only on its own screens ("Upgrade to Pro" and a small card on AddSynapse → Home, "Get Pro" in its row of the Plugins list, the Pro list on the chat's start screen and a "See Pro" button when you ask the chat for something only Pro does). An installed Pro add-on can turn these links off.

= How it works =

**With your own key (wp-admin chat):** the chat, the tools and your data stay in WordPress. Your server calls your AI provider's API directly (api.anthropic.com or api.openai.com) with your key; the tools run on your server, during the chat request of the logged-in user and with that user's permissions.

**From Claude:** Claude talks to AddSynapse, and AddSynapse calls your site's own REST routes with the connection key you confirmed, only for the allowed read tools and — after you press Approve — to write exactly the approved values. The plugin has no job queue, no background polling and no remote-command endpoint.

AddSynapse is operated by WebOrange (Ioannis Sakkoulas).

== Installation ==

1. In WordPress go to **Plugins → Add New Plugin**, search for **AddSynapse**, find **AddSynapse Store Assistant**, click **Install Now** and **Activate**.
2. Open **AddSynapse → Home**. The welcome wizard asks how you want to use it:
   * **From Claude or ChatGPT:** run the connectivity check, add AddSynapse to Claude (or to ChatGPT as a custom connector, beta), then ask it to connect your site and confirm the connection on this plugin's page. The steps turn green on their own.
   * **With your own AI key:** choose Anthropic or OpenAI, paste an API key and click **Save and test**, then open **Chat**.
3. Ask something about your store, open a draft, click **Approve** — and try **Undo**.

== Frequently Asked Questions ==

= Do I need an API key? =

Not to use it from Claude: your Claude subscription does the AI work. For the chat inside wp-admin, yes: your own Anthropic or OpenAI key.

= Do I need an AddSynapse account? =

Only to use your site from Claude (the connection runs through AddSynapse); the account is free and is created during the connection. The wp-admin chat with your own key needs no account and nothing in it is locked without one.

= Is it free? =

The plugin is free, and so is the wp-admin chat with your own key (you pay only your AI provider). Using it from Claude needs a free AddSynapse account with a daily allowance of actions; larger allowances are on paid plans at addsynapse.com. Undo never counts.

= Can Claude change my store without asking? =

No. Claude can only read through the allowed tools and prepare drafts. A change is written only after you press Approve, and the write needs a single-use approval signed at that moment: even someone holding the connection key cannot write without it.

= How does Undo work? =

Every approved change keeps the values from before. Undo writes them back — from the draft card in Claude, from your AddSynapse account, or from the preview card in wp-admin — and only if nothing else changed the same data since, so it never overwrites a newer edit.

= Does it work with ChatGPT? =

Yes, in two ways. The wp-admin chat works with an OpenAI key. And you can use your site from ChatGPT through AddSynapse (beta): add https://api.addsynapse.com/mcp as a custom connector in ChatGPT's developer mode — the wizard on **AddSynapse → Home** shows each step. Developer mode depends on your ChatGPT plan. If ChatGPT shows no Approve button for a draft, approve it from the link in the reply (your AddSynapse account).

= Can I connect more than one store? =

Yes. Connect as many stores and sites as you like to one AddSynapse account and choose which one to work on in Claude.

= What can AddSynapse read and write on my site? =

Read: store name and structure, products and variations, categories, stock and sales reports (sales totals and best sellers — never customer names, emails or addresses), and SEO texts. Write, only after you approve (on WooCommerce stores): prices, sales and stock of products and variations, SEO titles and meta descriptions of products, pages and posts, and coupons. Every write is listed in Settings → Approval log.

= How do I disconnect? =

Press **Disconnect AddSynapse** on **AddSynapse → Home**. Deactivating or deleting the plugin also revokes the AddSynapse connection key (and any Application Password WordPress issued to AddSynapse with an earlier version) on this site.

= Can the wp-admin chat change many products at once? =

The free chat edits one product at a time (one preview per product). Bulk editing in the wp-admin chat is part of Store Assistant Pro, a separate add-on.

= Where is my AI key stored, and who sees it? =

Encrypted in your WordPress database (not autoloaded), or in `wp-config.php` if you define `ADDSYNAPSE_ASSISTANT_ANTHROPIC_KEY` or `ADDSYNAPSE_ASSISTANT_OPENAI_KEY`. Only administrators can set or remove it; the screens show only its last four characters. It is sent only to your AI provider's API, never to the browser or to AddSynapse. **Remove my key** (or deleting the plugin) deletes it.

= How much does my own key cost? =

It depends on the model and on how much you use the chat. Each question sends the AI only the instructions and tools it needs, and repeated parts are cached by your provider where it supports that. Settings shows the calls, tokens and an estimated cost for this month and the last 30 days; your provider's dashboard shows the real bill.

= My host stops long requests. What can I do? =

Turn on **Compatibility mode** in Settings → Your own AI key: the assistant then makes one AI step per request and the chat continues by itself.

= Does it touch users, passwords or code? =

No. It cannot create, edit or delete users, change passwords, or run code or SQL.

= Does it work without WooCommerce? =

Yes. Connected to Claude, a plain WordPress site gets the site tools: pages and posts (read, edit title, excerpt and text, publish or unpublish), menus, featured images and SEO titles and descriptions — each change as a draft you approve, with exact Undo. The wp-admin chat reads pages, posts, menus, the media library and SEO. Product, order and coupon tools need WooCommerce.

= Which page builders and SEO plugins are supported? =

Pages (read): the block editor (Gutenberg/FSE) and Elementor. SEO: Rank Math and Yoast SEO.

= Who can use it? =

Users who can manage WooCommerce (or, without WooCommerce, edit the theme options) can use the chat. Setting the AI key and connecting the site are for administrators only.

= What is deleted when I delete the plugin? =

Deleting the plugin from the Plugins screen removes its tables (conversations, change history), its settings, its scheduled tasks, unused images uploaded in the chat and your AI key, and revokes the AddSynapse connection key (and any Application Passwords issued to AddSynapse by earlier versions). Changes already applied to your products and pages stay. Deactivating the plugin also revokes them; it removes no data.

= Where is the source code of the JavaScript? =

The readable source of the admin app is in the `src/` folder of the plugin, with `package.json` and `package-lock.json` (exact versions). Build it with `npm ci && npm run build` (output in `build/`).

== Screenshots ==

1. The welcome wizard on AddSynapse → Home: use it from Claude (no API key) or chat here with your own AI key.
2. Connecting to Claude or ChatGPT: short steps that turn green on their own, with two ways to add AddSynapse to your AI.
3. A draft in Claude: the exact before and after prices, with Approve — nothing changes until you press it.
4. Settings → Approval log: every change written after your approval, and every Undo.
5. The chat in wp-admin: a price change preview with Approve or Reject; approved changes can be undone.

== External services ==

The plugin uses external services only for the features you turn on. Slash commands, previews, Approve and Undo in wp-admin run on your site and need no service. **Before an administrator saves an AI key (section 1 or 2), starts the Claude connection (section 5), creates an account (section 4) or allows anonymous usage info (section 6), the plugin makes no outgoing requests** (except the section 5 setup question) and schedules no recurring background tasks (only local one-off work you start yourself: applying a large approved change, or removing an image you uploaded in the chat and never used). The connectivity check of the wizard is a request from your site to itself. The links to the Store Assistant Pro page on addsynapse.com and to the plugin's reviews on WordPress.org are ordinary links your browser opens when you click them.

= 1. Anthropic API — api.anthropic.com (only with your own Anthropic key) =

Used to answer in the wp-admin chat with your key, called by your server (never by the browser), only while a logged-in user chats. **Save and test** sends one tiny test message with no site data. Then, for each question to the AI (slash commands are never sent): one small call that picks the tools the question needs and a title for the conversation (it gets the message, the previous answer and the number of ticked products), and the main calls with the message and the conversation, the instructions of the assistant and the list of its tools, a summary of your site structure (active plugins, theme, taxonomies and attributes, currency, time zone, menu locations), the admin and site language, the ids of ticked products, and an image you attach in the chat: its name, size and type and the image itself, reduced to at most 1,024 pixels (on by default; a manager can turn it off in Settings → "The AI sees the images you upload", then only the name, size and type are sent; images are sent only with the message they are attached to), and the results of the tools the assistant used (product, category, page, menu, SEO and order data — customers only as anonymous references, never names, emails, phone numbers or addresses). Your key is sent in the request header.

* Anthropic commercial terms: https://www.anthropic.com/legal/commercial-terms
* Anthropic privacy policy: https://www.anthropic.com/legal/privacy

= 2. OpenAI API — api.openai.com (only with your own OpenAI key) =

The same data as in section 1, sent the same way and at the same moments, with `store: false` (OpenAI is asked not to keep the responses).

* OpenAI terms: https://openai.com/policies/business-terms
* OpenAI privacy policy: https://openai.com/policies/privacy-policy

= 3. Browser speech recognition — voice input (optional, off by default) =

Only if a manager turns on **Voice input** in Settings. The plugin sends nothing for this feature: it asks the manager's browser to recognize speech (the browser's Web Speech API) after the microphone button is pressed. Depending on the browser, the audio may be sent to the browser vendor for recognition (Chrome: Google, Edge: Microsoft, Safari: Apple or on the device), under the vendor's own terms:

* Google privacy policy: https://policies.google.com/privacy
* Microsoft privacy statement: https://www.microsoft.com/en-us/privacy/privacystatement
* Apple privacy policy: https://www.apple.com/legal/privacy/

= 4. AddSynapse — api.addsynapse.com (only if you create an account from the plugin) =

Optional. After "Save and test" succeeds, the plugin's own screens show a card "Create a free AddSynapse account" (with "Skip"; it comes back at most once, 30 days later). Nothing is sent unless an administrator clicks **Create account**. Then, once (`POST /api/v1/assistant/optin` on api.addsynapse.com): the email in the card (prefilled with the site's admin email, editable), the site's address (home URL), whether the "Send me news and offers" box was ticked (unticked by default; news are emailed only after you confirm a link AddSynapse sends) and the version of the consent text. AddSynapse creates the account and returns a site key (stored encrypted). Your AI key, chats and store data are never sent.

With an account, at most once a day and only while a user opens the plugin's own screens, the plugin asks AddSynapse for short announcements (`POST /api/v1/assistant/announcements` on api.addsynapse.com; text and at most one link, shown only on the plugin's screens, dismissible, no images or tracking). It sends the site key, the plugin version and the site language. "Disconnect this site" in Settings (or deleting the plugin) stops this.

* AddSynapse terms of service: https://addsynapse.com/terms
* AddSynapse privacy policy: https://addsynapse.com/privacy

= 5. AddSynapse for Claude and other AI apps — addsynapse.com (after you approve the connection; one setup question before) =

Optional: use your site from Claude or ChatGPT. "Connect to AddSynapse" opens app.addsynapse.com with this site's address in the link. While nothing is set up, for an administrator (after activation or on the setup screen, at most every 10 minutes), the plugin asks whether you started in Claude: `GET https://api.addsynapse.com/api/onboarding/pending?site=<site address>` (site address only). If yes, the browser continues there; nothing connects without Yes, connect.

You start the connection from your AI chat. It opens this plugin's page on your site. For a logged-in administrator only, that page asks AddSynapse which account started the connection (`POST https://api.addsynapse.com/api/v1/assistant/relay-claim/preview`: the one-time code and site address; answer: account name and masked email) and asks you to confirm it. Only if you press Connect does the plugin create a random connection key for AddSynapse, acting as you, and send it once from your server over HTTPS (`POST https://api.addsynapse.com/api/v1/assistant/relay-claim`: key, user name, site address, one-time code — never in a web address); Cancel creates nothing. The site keeps only a keyed hash of the key. A key AddSynapse does not use within 15 minutes is revoked. Sites connected with an earlier version keep their WordPress Application Password until they reconnect.

That key works only on this plugin's own `addsynapse-assistant/v1/remote/*` routes and only over HTTPS (no users, plugins, settings, other REST routes or XML-RPC). With it, AddSynapse's servers call your site, when you ask your AI app, to read: the site or store name and structure, pages, posts, menus and media, products and variations, categories, stock and catalogue reports, sales totals and best sellers (never customer names, emails or addresses), and SEO titles and descriptions. Only after you press Approve on a draft (or Undo), and only with a single-use approval ticket that AddSynapse signs at that moment (Ed25519, valid 5 minutes, bound to this site and to the exact values), they write the approved values: titles, excerpts, content and status of pages and posts, menus, featured images, SEO titles and meta descriptions, and on WooCommerce stores prices, sale prices and stock of products and variations, and coupons. Each write is listed in Settings → Approval log. Disconnect at any time with Disconnect AddSynapse on AddSynapse → Home; deactivating or deleting the plugin revokes the key too.

Before you connect, the AddSynapse setup page may check whether this plugin is active on your site by opening a public route of it (`addsynapse-assistant/v1/hello`) that answers only the plugin name, that it supports AI-app connections and whether its version is recent enough. This is a request from AddSynapse to your site; the plugin sends nothing for it.

* AddSynapse terms of service: https://addsynapse.com/terms
* AddSynapse privacy policy: https://addsynapse.com/privacy

= 6. AddSynapse — api.addsynapse.com (only if you allow anonymous usage info) =

Optional and off by default, separate from the account and from your AI key. The plugin's own screens show a notice "Help improve Store Assistant" with **Allow** and **No thanks** (once; "No thanks" hides it for good). Nothing is sent and nothing is scheduled unless an administrator clicks **Allow** (or **Turn on** in Settings → "Anonymous usage info"). Then the plugin sends `POST https://api.addsynapse.com/api/v1/assistant/usage` once right away and after that at most once a week (WP-Cron), with: a random ID created on your site when you allowed it (not derived from the site, its address or any user), the plugin, WordPress and WooCommerce versions, the site language and the version of the consent text. Never the site address, an email, content, store or customer data, or your AI key. AddSynapse uses it only to count active installations and versions. **Turn off** in Settings, deactivating or deleting the plugin stops the calls and deletes the random ID on your site.

* AddSynapse terms of service: https://addsynapse.com/terms
* AddSynapse privacy policy: https://addsynapse.com/privacy

== Changelog ==

= 1.3.5 =
* Improved: simpler setup. Step 2 is one «Connect to AddSynapse» button that links this site to your AddSynapse account; adding AddSynapse to Claude or ChatGPT moves to step 3, shown after the site is connected.
* Improved: started in Claude? Right after activation the plugin asks AddSynapse once (site address only, see External services) and goes straight to «Connect <site> to your AddSynapse account? Yes, connect», then «Done — go back to Claude». «Other options» keeps both ways one click away.
* Improved: setup has two steps (the connection check runs by itself) and Home opens with «You're all set!»: open Claude and three requests to try.
* Fixed: sites behind an HTTPS proxy (PHP sees plain HTTP) can connect; a failed request to AddSynapse now says what failed.

= 1.3.4 =
* New: the connection to AddSynapse no longer needs WordPress Application Passwords. You confirm it on this plugin's page, which creates its own key, valid only for this plugin's tools. Sites where Application Passwords are turned off (for example by a security plugin's default settings) can now connect.
* New: «Disconnect AddSynapse» on Home.
* New: for safety, a coupon above 50% off that your AI prepares is approved by a site administrator on AddSynapse → Approvals in wp-admin (with Undo there), never in the chat. The site's admin email gets a short notice when a coupon is approved from AddSynapse.
* Improved: the connection key keeps working behind shared proxies, stops at once if its administrator loses admin rights, and a connection is refused when AddSynapse cannot confirm the account.
* Improved: what an administrator decides on AddSynapse → Approvals (approved, discarded, undone) also shows on AddSynapse's Changes page.
* Sites already connected keep working; reconnecting moves them to the new key.

= 1.3.3 =
* New: change your site's appearance by talking to Claude or ChatGPT — global styles (colors, fonts, spacing), Additional CSS, block templates and template parts (header, footer), theme settings and Elementor (pages and the site kit). Every change is a preview card with Approve and Undo.
* Improved: the account confirmation step now appears inside the setup wizard instead of a separate screen.
* Improved: after you approve the connection, you return to the setup wizard to finish.
* Fix: the authorize screen layout — long codes wrap and no longer overflow.

= 1.3.2 =
* Fix: two connect links approved in two browser tabs before either «Connect» now both finish — the second shows «Already connected ✓» instead of «not completed».
* Fix: going Back to the confirmation screen after connecting shows «Already connected ✓», not an error.
* Improved: Home no longer asks for a second approval when the site is already connected; connecting another AI account is a small optional section.
* New: Home shows your AddSynapse plan and today's actions (Free: 100 actions a day), with a «Change plan» link.

= 1.3.1 =
* New: use your store or site from ChatGPT too (beta), as a custom MCP connector. The setup wizard shows the steps, with the address to copy. Claude stays the recommended way.
* Improved: the «Add AddSynapse to your AI» step offers two clear ways — Claude in one click from its connector directory, or a custom connector by address (ChatGPT, Claude, other AI apps) — and turns «Done» only once the site is connected or you press «I've added it».
* Improved: a short note before WordPress' own approval screen explains what «Yes, I approve» gives AddSynapse.
* Fix: opening the approval return page twice no longer shows «not completed» or removes the working connection.
* New: plain WordPress sites (no WooCommerce) get the full site tools from Claude — pages and posts (title, excerpt, text, publish/unpublish), menus, featured images and SEO — each as a draft you approve, with exact Undo.
* Security: a confirmation screen before connecting names the AddSynapse account that asked («Connect this site to the AddSynapse account …?», Connect or Cancel); until you answer, the new password waits at most 10 minutes, encrypted, split between a temporary option and a cookie of your browser.
* Security: a new connection becomes active only once AddSynapse uses it (the previous one keeps working until then); an unused one is revoked after 15 minutes. Only AddSynapse's own approval key is trusted for writes, and the public check route no longer tells anonymous visitors the exact plugin version.
* Fix: setup wizard — clearer «Add to your AI» step and «Done» only when the site is really connected.

= 1.3.0 =
* New: use your store or site from Claude, with no API key. Claude prepares drafts — prices and sales, stock, SEO titles and meta descriptions, coupons — that you approve with one click in the chat, and every approved change can be undone exactly. Connect several stores and sites to one AddSynapse account.
* New: a welcome wizard on AddSynapse → Home. Choose how to use it — from Claude (no API key) or the chat here with your own AI key — and follow short steps that turn green on their own. The menu is now Home, Chat and Settings (old addresses still work).
* New: plain WordPress sites (no WooCommerce) can connect too, to read the SEO of pages and posts.
* New: Settings → Approval log lists every change written after your approval, and every Undo.
* Improved: low-stock reports use each product's own low-stock threshold and list products that do not track stock; variable products show their price range.
* Security: the connection uses an Application Password limited to the plugin's own routes, and every write needs a single-use approval signed at the moment you approved. Deactivating or deleting the plugin revokes every Application Password WordPress issued to AddSynapse (for all users of the site).
* Optional anonymous usage info, off by default: a one-time notice on the plugin's own screen asks whether the site may share the plugin, WordPress and WooCommerce versions, the site language and a random ID once a week. "No thanks" hides it for good; Settings → "Anonymous usage info" turns it on or off. See External services, section 6.

= 1.2.0 =
* Leaner AI requests: a much smaller set of instructions, and only the tools and instructions a question needs (for example about half the input of 1.1.2 for a stock question, about a quarter for a request only Pro does) — less cost per question on your own key.
* Store Assistant Pro, the separate add-on, is available: "Upgrade to Pro" in the AddSynapse menu, "Get Pro" in the Plugins list, a card in Settings, and a "See Pro" button when you ask for something only Pro does. Nothing in this plugin is locked, and an installed Pro add-on can turn the links off.
* The optional account card says what the account is for and has "Skip"; the chat no longer shows "Get notified".
* After ten approved changes, a one-time request for a review on WordPress.org, only on the plugin's own screen; "Don't ask again" hides it for good.
* For developers: tools of another plugin can declare a `group` (described with the filter `addsynapse_assistant_extension_groups`); a group reaches the AI only when a question needs it.

= 1.1.2 =
* Improved description.
* Admin bar shortcut to the assistant.

= 1.1.1 =
* Renamed display name to "AddSynapse – AI Website & Store Assistant for WooCommerce" (slug, settings and data unchanged).

= 1.1.0 =
* Free with your own AI key: paste an Anthropic or OpenAI API key in Settings (or in the chat's first screen) and every feature works. The key is stored encrypted, only administrators can change it, and it is sent only to your AI provider.
* Live (streamed) answers, a quick first step that picks the tools each question needs, and a compatibility mode for hosts with short time limits.
* Usage and estimated cost of your own key in Settings.
* The plugin no longer connects to the hosted AddSynapse service (no connection, trial or subscription in the plugin); the settings of that connection are removed on update.
* One product per change: price/sale, stock, existing categories/tags/brand, name and texts, main image and gallery, product SEO. Reads stay complete. Bulk changes, CSV imports, managing categories/tags/brands, attributes and variations, custom fields, coupons, orders, menus, page and post changes, the «Show me» picker and the change history list are no longer part of this plugin (coming in the separate Store Assistant Pro add-on); Undo stays on every preview card.
* Images: drag and drop or paste an image into the chat (like the paperclip) and ask the assistant to make it a product's main or gallery image. An attached image is sent (reduced) to your AI provider so it can see it; turn this off in Settings → "The AI sees the images you upload".
* Settings: the Model list shows only the models of the selected provider, recommended first.
* Optional: after the key works, a card offers a free AddSynapse account, to be told first when Store Assistant Pro launches. Nothing is sent unless you click Create account; with an account, short announcements on the plugin's screens (at most once a day).
* For developers: filters that let another plugin add tools and instructions to a chat request.

= 1.0.2 =
* No remote administration: the background job channel was removed (no polling, no wake-up endpoint, no approval links). The AddSynapse service only answers chat requests sent by a logged-in user in wp-admin; every tool runs during that request with the user's permissions, and every change is approved in wp-admin.
* Readme: a clear section on the external AI service, what it receives and that nothing changes without approval.
* The "Show me" page picker escapes the page content it returns.
* Plugin name: "AddSynapse – AI Store Assistant for WooCommerce" (slug and text domain unchanged).

= 1.0.1 =
* The AddSynapse dashboard can only read data and prepare drafts on your site: approving or undoing from the dashboard was removed — every change is approved in wp-admin.
* The new-block preview is a page served by the plugin with an enqueued stylesheet; draft page previews are escaped.
* Translations come from translate.wordpress.org.

= 1.0.0 =
* First release on WordPress.org: chat in wp-admin, read tools, previews with Approve and Undo for prices, stock, categories, attributes, variations, product details, menus, pages, SEO, orders and coupons, CSV import, 5-day free trial without sign-up, then a free read-only plan; Subscribe from the plugin. Bulk previews show how many questions they count (10 products = 1 question). Slash commands, previews, Approve, Undo, voice input and Show me work for everyone, without a connection or subscription.

== Upgrade Notice ==

= 1.3.5 =
Simpler setup: one «Connect to AddSynapse» button links the site to your account; started in Claude, you land right on «Yes, connect».

= 1.3.4 =
Connect without WordPress Application Passwords, with a key only this plugin accepts. Coupons above 50% off are approved only in wp-admin. Includes 1.3.3: change your site's appearance from Claude or ChatGPT, with Approve and Undo.

= 1.3.3 =
New: change your site's appearance (styles, CSS, templates, theme settings, Elementor) from Claude or ChatGPT, with Approve and Undo. Smoother setup wizard.

= 1.3.2 =
Fixes connecting from two tabs at once and Back after connecting. Home shows your plan and today's actions, and no longer asks for a second approval.

= 1.3.1 =
New: manage plain WordPress sites from Claude (pages, posts, menus, featured images, SEO). New (beta): ChatGPT as a custom connector. Security: a confirmation screen before connecting and hardened connection hand-over.

= 1.3.0 =
New: use your store from Claude with no API key — drafts you approve in the chat, exact Undo, many stores in one account. New welcome wizard. Optional, off-by-default anonymous usage info.

= 1.2.0 =
Lower AI cost per question (leaner requests), links to the new Store Assistant Pro add-on, and a one-time review request.

= 1.1.2 =
A shortcut to the assistant in the admin bar and a clearer description.

= 1.1.1 =
New display name only; nothing else changes.

= 1.1.0 =
The assistant now runs with your own Anthropic or OpenAI API key, one item per change. The hosted AddSynapse connection and the bulk tools are no longer part of this plugin.

= 1.0.2 =
The AddSynapse service can no longer start anything on your site: it only answers your chat in wp-admin.

= 1.0.1 =
Every change is now approved only in wp-admin; the AddSynapse dashboard can read data and prepare drafts.

= 1.0.0 =
First release.
