=== AddSynapse – AI Website & Store Assistant for WooCommerce ===
Contributors: sakkoulas
Tags: ai, ai assistant, woocommerce, ai agent, claude
Requires at least: 6.9
Tested up to: 7.1
Requires PHP: 7.4
WC requires at least: 8.0
WC tested up to: 11.1
Stable tag: 1.2.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

AI assistant in wp-admin for WordPress and WooCommerce: ask about sales, stock and SEO, edit products with Undo. Free with your Claude or OpenAI key.

== Description ==

**AddSynapse – AI Website & Store Assistant for WooCommerce** is an AI assistant for WordPress and WooCommerce that
lives in wp-admin (**AddSynapse → Store Assistant**). Shop managers ask, in plain words and in their own language, about
their store and their website, and for the product changes they would otherwise click through screen by screen:
"which products are out of stock?", "what were my best sellers last month?", "put the Linen Shirt on sale at 29.90
until Sunday", "set this photo as the main image of the blue mug", "write a better SEO title for the Linen Shirt".

It works as an AI agent with a fixed set of checked tools: it looks the data up itself, then prepares a preview of the
change, and nothing changes until you click **Approve**.

= Free with your own Claude or OpenAI API key =

Paste an Anthropic (Claude) or OpenAI (GPT) API key in **Settings** (or in the chat's first screen) and every feature of
the plugin works, with no account and no plan. It edits one product at a time. You pay your AI provider directly for
what you use; the Settings screen shows an estimate of your usage and cost. The key is stored encrypted on your site
(or in `wp-config.php`) and is never sent to the browser or to AddSynapse.

No account is needed. After your key works, the plugin offers once an optional, free AddSynapse account (your sites in
one place on addsynapse.com) — nothing is locked without it, and nothing is sent unless you click **Create account**.

Each question costs as little as possible on your key: the assistant sends the AI a small set of instructions and only
the tools that question needs.

Everything that runs on your site also works before you add a key: slash commands (for example `/report low_stock` or
`/price id=123 -10%`), the previews, Approve, Undo and voice input.

= WooCommerce reports in plain words =

Search products (by name, SKU, category, tag, brand, attribute, price, stock) and ask for low stock, out of stock,
products missing images, descriptions or prices, best sellers, sales by day, week or month, recent orders and top
customers (as references only — no names or emails leave your site). Sales answers come with simple charts.

= AI product descriptions and product SEO, one product at a time =

* **Product descriptions:** the AI writes, rewrites, shortens or translates the name, short description and description
  of a product, and you see the new text in the preview before you approve it.
* **Product SEO:** a new SEO title, meta description and focus keyword for a product, in Rank Math or Yoast SEO, with
  their lengths checked.
* **Product images:** drop or paste a photo into the chat and make it the main image or add it to the gallery.
* **Prices and stock:** price and sale price with dates, and the stock quantity.
* **Categories, tags and brand:** assign the product to existing ones.

Each change covers one product. When you ask for many at once, the assistant says so and offers to do one now.

= Your WordPress website, also without WooCommerce =

The assistant reads your pages (Gutenberg and Elementor), menus, media library and the SEO of pages and posts, so you
can ask "which pages have no meta description?" or "what is in the main menu?". On a plain WordPress site (no
WooCommerce) it answers about the site; changes to pages, posts and menus are part of Store Assistant Pro.

= Voice and images =

Type or speak (optional voice input, off by default) and attach an image to a message. The AI can see an attached image
(a reduced copy; you can turn this off in Settings).

= Store Assistant Pro (separate add-on) =

**Store Assistant Pro** is a separate, paid add-on by AddSynapse for work beyond single products, for example:

* **AI blog writing:** drafting blog posts and other content.
* **Bulk edit products:** bulk price changes by category or brand, CSV imports.
* **SEO at scale:** SEO titles, meta descriptions and image alt text for many products, pages and posts at once.
* **Pages, menus and design:** editing pages, sections, menus and the site's appearance by chat.
* **Catalog and sales:** managing categories, tags, brands, attributes and variations, coupons and orders, and a full
  change history.
* **Our AI included:** use it without an API key of your own.

Plans: https://addsynapse.com/store-assistant. Pro is not included in this plugin and this plugin does not check for it:
everything described in the sections above is complete and free. The plugin shows plain links to the add-on's page
only on its own screens: "Upgrade to Pro" in the AddSynapse menu, "Get Pro" in its row of the Plugins list, a card in
Settings, the Pro list of the chat's start screen and a "See Pro" button when you ask the chat for something only Pro
does. An installed Pro add-on can turn these links off.

= Every change is a preview a person approves =

The assistant only reads data and prepares drafts. Nothing on your site changes until a logged-in user with the right
permissions clicks **Approve** on the preview here in wp-admin, and every approved change can be undone (**Undo**) as
long as nothing else changed the same data since. The assistant never creates, edits or deletes user accounts, never
changes passwords and never runs code or SQL: it can only ask the plugin for a fixed set of checked operations.

= How it works =

The chat, the tools and your data stay in WordPress. Your AI provider decides which tool to use next and writes the
answers; the tools themselves run on your server, during the chat request of the logged-in user and with that user's
permissions.

Nothing can start anything on your site from outside. The plugin has no job queue, no background polling and no
"wake-up" or remote-command endpoint: the AI only answers the chat requests that a logged-in user sends from wp-admin,
and every change is applied only when a logged-in user approves its preview here in wp-admin.

= The AI runs on an external service =

The AI is not part of the plugin: your server calls your AI provider's API directly (api.anthropic.com or
api.openai.com) with your key. For a question to the AI the plugin sends the text of your chat message and the store
and site data the AI needs to answer it (the results of the tools it used: products, categories, pages, menus, SEO and
order data — customers only as anonymous references), plus a summary of your site structure, and an image you attach
to that message (a reduced copy; it can be turned off in Settings). Nothing is sent before an administrator saves a
key, and slash commands are never sent. The exact list of calls is in **External services**
below.

AddSynapse is operated by WebOrange (Ioannis Sakkoulas).

== External services ==

The AI of the assistant is an external service: your own AI provider (sections 1 and 2). Slash commands, previews,
Approve and Undo run on your site and need no service. **Nothing is sent anywhere until an administrator clicks "Save
and test"** in AddSynapse → Store Assistant → Settings, next to a text that lists what is sent. Before that, the plugin
makes no outgoing requests and schedules no recurring background tasks (only local one-off work that you start
yourself: applying a large approved change, or removing an image you uploaded in the chat and never used). The plugin
contacts AddSynapse only if an administrator creates an AddSynapse account from the plugin (section 4). The links to
the Store Assistant Pro page on addsynapse.com and to the plugin's reviews on WordPress.org are ordinary links that
your browser opens when you click them; the plugin sends nothing for them.

= 1. Anthropic API — api.anthropic.com (only with your own Anthropic key) =

Used to answer in the chat with your key, called by your server (never by the browser), only while a logged-in user
chats. **Save and test** sends one tiny test message with no site data. Then, for each question to the AI (slash
commands are never sent): one small call that picks the tools the question needs and a title for the conversation (it
gets the message, the previous answer and the number of ticked products), and the main calls with the message and the
conversation, the instructions of the assistant and the list of its tools, a summary of your site structure (active
plugins, theme, taxonomies and attributes, currency, time zone, menu locations), the admin and site language, the ids
of ticked products, and an image you attach in the chat: its name, size and type and the image itself, reduced to at
most 1,024 pixels (on by default; a manager can turn it off in Settings → "The AI sees the images you upload", then only
the name, size and type are sent; images are sent only with the message they are attached to), and
the results of the tools the assistant used (product, category, page, menu, SEO and order
data — customers only as anonymous references, never names, emails, phone numbers or addresses). Your key is sent in
the request header.

* Anthropic commercial terms: https://www.anthropic.com/legal/commercial-terms
* Anthropic privacy policy: https://www.anthropic.com/legal/privacy

= 2. OpenAI API — api.openai.com (only with your own OpenAI key) =

The same data as in section 1, sent the same way and at the same moments, with `store: false` (OpenAI is asked not to
keep the responses).

* OpenAI terms: https://openai.com/policies/business-terms
* OpenAI privacy policy: https://openai.com/policies/privacy-policy

= 3. Browser speech recognition — voice input (optional, off by default) =

Only if a manager turns on **Voice input** in Settings. The plugin sends nothing for this feature: it asks the
manager's browser to recognize speech (the browser's Web Speech API) after the microphone button is pressed.
Depending on the browser, the audio may be sent to the browser vendor for recognition (Chrome: Google, Edge:
Microsoft, Safari: Apple or on the device), under the vendor's own terms:

* Google privacy policy: https://policies.google.com/privacy
* Microsoft privacy statement: https://www.microsoft.com/en-us/privacy/privacystatement
* Apple privacy policy: https://www.apple.com/legal/privacy/

= 4. AddSynapse — api.addsynapse.com (only if you create an account from the plugin) =

Optional. After "Save and test" succeeds, the plugin's own screens show a card "Create a free AddSynapse account"
(with "Skip"; it comes back at most once, 30 days later). Nothing is sent unless an administrator clicks
**Create account**. Then, once: the email in the card (prefilled with the site's admin email, editable), the shop's
address (home URL), whether the "Send me news and offers" box was ticked (unticked by default; news are emailed only
after you confirm a link AddSynapse sends) and the version of the consent text. AddSynapse creates the account and
returns a site key (stored encrypted). Your AI key, chats and store data are never
sent.

With an account, at most once a day and only while a user opens the plugin's own screens, the plugin asks AddSynapse
for short announcements (text and at most one link, shown only on the plugin's screens, dismissible, no images or
tracking). It sends the site key, the plugin version and the site language. "Disconnect this site" in Settings (or
deleting the plugin) stops this.

* AddSynapse terms of service: https://addsynapse.com/terms
* AddSynapse privacy policy: https://addsynapse.com/privacy

== Installation ==

1. Install the plugin from Plugins → Add New, or upload the ZIP, and activate it. WooCommerce is needed for changes;
   without it the assistant reads pages, menus and SEO.
2. Go to **AddSynapse → Store Assistant → Settings**.
3. Under **Your own AI key**, choose Anthropic or OpenAI, paste an API key and click **Save and test**.
4. Open the **Chat** tab and ask something about your site.

== Frequently Asked Questions ==

= Do I need an account? =

No. The plugin works fully with only your own AI key; it needs no AddSynapse account and never asks for one to unlock
anything. Creating a free account from the card in the plugin is optional; see External services, section 4.

= How can I test the plugin without paying? =

Without any key, slash commands already work in the Chat tab — for example `/report low_stock`, or
`/price id=123 -10%` followed by **Approve** and **Undo** on its preview; the plugin makes no outgoing requests. For the
AI, paste an Anthropic or OpenAI API key in Settings → **Your own AI key** and click **Save and test** (your provider
bills you for what you use; small models cost little). Then ask, for example, "which products are low on stock?" or
"lower the price of product X by 10%", open the preview card, click **Approve**, check the product and click **Undo**.

= What is free? =

The whole plugin. Every feature in it works with your own AI key, with no account or plan; you pay only your AI
provider. It edits one product at a time. Slash commands, previews, Approve, Undo and voice input work for everyone, with no key.

= What is Store Assistant Pro? =

A separate, paid add-on by AddSynapse for work beyond single products (bulk changes, CSV imports, managing categories,
attributes and variations, coupons and orders, the site's appearance, new content, a full change history, and an AI
without your own key): https://addsynapse.com/store-assistant. This plugin does not contain it, does not check for it
and works the same without it.

= Can I change many products at once? =

This plugin edits one product at a time (one preview per product). Bulk editing is part of Store Assistant Pro, a
separate add-on.

= Can it write product descriptions? =

Yes, one product at a time: ask it to write, improve or translate a product's name, short description or description.
The new text appears in a preview; nothing changes until you click **Approve**, and **Undo** brings the old text back.

= Can it write blog posts? =

Not in this plugin. It reads your posts and their SEO, but writing and publishing blog posts (AI blog writing) is
part of Store Assistant Pro, a separate add-on.

= Where is my AI key stored, and who sees it? =

Encrypted in your WordPress database (not autoloaded), or in `wp-config.php` if you define
`ADDSYNAPSE_ASSISTANT_ANTHROPIC_KEY` or `ADDSYNAPSE_ASSISTANT_OPENAI_KEY`. Only administrators can set or remove it;
the screens show only its last four characters. It is sent only to your AI provider's API, never to the browser or to
AddSynapse. **Remove my key** (or deleting the plugin) deletes it.

= How much does my own key cost? =

It depends on the model and on how much you use the chat. Each question sends the AI only the instructions and tools
it needs, and repeated parts are cached by your provider where it supports that. Settings shows the calls, tokens and an estimated cost for this
month and the last 30 days, at the providers' list prices; your provider's dashboard shows the real bill. The default
models are the small, fast ones.

= My host stops long requests. What can I do? =

Turn on **Compatibility mode** in Settings → Your own AI key: the assistant then makes one AI step per request and the
chat continues by itself.

= Can the assistant change my site without asking? =

No. Its tools only read data or prepare a draft. A logged-in user with the right permissions has to click **Approve**
on the preview here in wp-admin. Approved changes can be undone from their preview card.

= What can the AI do on my site? =

Nothing on its own. It only answers the chat requests that a logged-in user sends from wp-admin; it cannot start a
tool, a job or any other action on your site, and the plugin has no endpoint that accepts commands from outside.
During your chat request the assistant's tools run on your server with your permissions and only read data or prepare
drafts (stored in the plugin's own tables). Every change is approved by a logged-in user here in wp-admin.

= Does it touch users, passwords or code? =

No. It cannot create, edit or delete users, change passwords, or run code or SQL.

= Does it work without WooCommerce? =

Yes, to read pages, menus, the media library and SEO. Changes are product changes, so they need WooCommerce.

= Which page builders and SEO plugins are supported? =

Pages (read): the block editor (Gutenberg/FSE) and Elementor. SEO: Rank Math and Yoast SEO. Pages built with other
builders are listed in Settings as "needs a human" — the assistant does not guess.

= Who can use the chat? =

Users who can manage WooCommerce (or, without WooCommerce, edit the theme options). Setting the AI key is for
administrators only.

= What is deleted when I delete the plugin? =

Deleting the plugin from the Plugins screen removes its tables (conversations, change history), its settings and its
scheduled tasks, and images uploaded in the chat that were never used. Changes already applied to your products and
pages stay, and your AI key is deleted. Deactivating the plugin removes nothing.

= Where is the source code of the JavaScript? =

The readable source of the admin app is in the `src/` folder of the plugin, with `package.json` and
`package-lock.json` (exact versions). Build it with `npm ci && npm run build` (output in `build/`).

== Changelog ==

= 1.2.0 =
* Leaner AI requests: a much smaller set of instructions, and only the tools and instructions a question needs (for
  example about half the input of 1.1.2 for a stock question, about a quarter for a request only Pro does) — less cost
  per question on your own key.
* Store Assistant Pro, the separate add-on, is available: "Upgrade to Pro" in the AddSynapse menu, "Get Pro" in the
  Plugins list, a card in Settings, and a "See Pro" button when you ask for something only Pro does. Nothing in this
  plugin is locked, and an installed Pro add-on can turn the links off.
* The optional account card says what the account is for and has "Skip"; the chat no longer shows "Get notified".
* After ten approved changes, a one-time request for a review on WordPress.org, only on the plugin's own screen;
  "Don't ask again" hides it for good.
* For developers: tools of another plugin can declare a `group` (described with the filter
  `addsynapse_assistant_extension_groups`); a group reaches the AI only when a question needs it.

= 1.1.2 =
* Improved description.
* Admin bar shortcut to the assistant.

= 1.1.1 =
* Renamed display name to "AddSynapse – AI Website & Store Assistant for WooCommerce" (slug, settings and data unchanged).

= 1.1.0 =
* Free with your own AI key: paste an Anthropic or OpenAI API key in Settings (or in the chat's first screen) and every
  feature works. The key is stored encrypted, only administrators can change it, and it is sent only to your AI provider.
* Live (streamed) answers, a quick first step that picks the tools each question needs, and a compatibility mode for
  hosts with short time limits.
* Usage and estimated cost of your own key in Settings.
* The plugin no longer connects to the hosted AddSynapse service (no connection, trial or subscription in the plugin);
  the settings of that connection are removed on update.
* One product per change: price/sale, stock, existing categories/tags/brand, name and texts, main image and gallery,
  product SEO. Reads stay complete. Bulk changes, CSV imports, managing categories/tags/brands, attributes and
  variations, custom fields, coupons, orders, menus, page and post changes, the «Show me» picker and the change history
  list are no longer part of this plugin (coming in the separate Store Assistant Pro add-on); Undo stays on every
  preview card.
* Images: drag and drop or paste an image into the chat (like the paperclip) and ask the assistant to make it a
  product's main or gallery image. An attached image is sent (reduced) to your AI provider so it can see it; turn
  this off in Settings → "The AI sees the images you upload".
* Settings: the Model list shows only the models of the selected provider, recommended first.
* Optional: after the key works, a card offers a free AddSynapse account, to be told first when Store Assistant Pro
  launches. Nothing is sent unless you click Create account; with an account, short announcements on the plugin's
  screens (at most once a day).
* For developers: filters that let another plugin add tools and instructions to a chat request.

= 1.0.2 =
* No remote administration: the background job channel was removed (no polling, no wake-up endpoint, no approval
  links). The AddSynapse service only answers chat requests sent by a logged-in user in wp-admin; every tool runs
  during that request with the user's permissions, and every change is approved in wp-admin.
* Readme: a clear section on the external AI service, what it receives and that nothing changes without approval.
* The "Show me" page picker escapes the page content it returns.
* Plugin name: "AddSynapse – AI Store Assistant for WooCommerce" (slug and text domain unchanged).

= 1.0.1 =
* The AddSynapse dashboard can only read data and prepare drafts on your site: approving or undoing from the dashboard
  was removed — every change is approved in wp-admin.
* The new-block preview is a page served by the plugin with an enqueued stylesheet; draft page previews are escaped.
* Translations come from translate.wordpress.org.

= 1.0.0 =
* First release on WordPress.org: chat in wp-admin, read tools, previews with Approve and Undo for prices, stock,
  categories, attributes, variations, product details, menus, pages, SEO, orders and coupons, CSV import, 5-day free
  trial without sign-up, then a free read-only plan; Subscribe from the plugin. Bulk previews show how many questions
  they count (10 products = 1 question). Slash commands, previews, Approve, Undo, voice input and Show me work for
  everyone, without a connection or subscription.

== Upgrade Notice ==

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
