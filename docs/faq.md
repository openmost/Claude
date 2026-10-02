## FAQ

__How do I install and configure this plugin?__

1. Install and activate **Claude** from **Administration > Platform > Marketplace**.
2. As a super user, open **Administration > System > Claude** and set the API key and model in the **Connection** card, or connect Anthropic in **Administration > System > AI Providers**.
3. Optionally, override the settings for a website in **Administration > Websites > Claude**.

You can also download the plugin from [GitHub](https://github.com/openmost/Claude), extract it to your `plugins/` folder and activate it.

__What do I need to make it work?__

One of: an Anthropic API key (create one in the Claude Console, https://platform.claude.com), an HTTPS proxy or gateway that serves the Anthropic Messages API, or the Anthropic provider connected in AI Providers. On a custom host, the API key is optional: without a key, no `x-api-key` header is sent.

__Which API key is used?__

One key is enough. The plugin uses, in this order: the API key set for the website in **Administration > Websites > Claude**, then AI Providers when its provider is Anthropic, then the API key of the general settings. Keys are never copied from one place to another.

When Anthropic is connected in AI Providers, it takes over: the host, API key and model of the general settings are displayed read-only, and are used again if AI Providers is deactivated. A key set for a website still overrides AI Providers for that website.

__AI Providers is connected to another provider. Does Claude use it?__

No. The plugin only takes over AI Providers when its provider is Anthropic: the credential of another provider is never sent anywhere by this plugin. With another provider, Claude uses its own API key, and the chat recommends connecting Anthropic in AI Providers when no key is set.

__How do I remove a saved API key?__

Use the **Delete key** button of the **Connection** card, on the general settings page or on the settings page of the website. Leaving the field empty keeps the saved key.

__Which models are in the preset list?__

- Latest recommended (default): follows the model recommended by each plugin release, currently Claude Sonnet 5.5, the best balance of speed and quality for a conversation about your reports
- Claude Sonnet 5.5, Claude Opus 5.5, Claude Fable 5.1, Claude Haiku 4.5
- Previous models still served by Anthropic: Claude Opus 5, Claude Sonnet 5, Claude Fable 5, Claude Opus 4.8, Claude Sonnet 4.6

Type any other model ID in the **Model (Custom)** field.

__The chat says my model is not available__

Anthropic retires old models, and some models are not available to every account. Choose another model in the settings, for example "Latest recommended". The chat links to the settings page you can change.

__The chat says my key was refused, or that Anthropic is overloaded__

A refused key (HTTP 401) means the key is wrong, revoked or expired: check it in the settings. A rate limit (HTTP 429) tells you when to try again, and an overloaded API (HTTP 529) is temporary: the plugin retries a few times before it reports the error.

__What is the agent mode?__

When the **McpServer** plugin is enabled, the chat and the insight panel work as an agent: they query your live reports, websites, goals, dimensions and segments through the Matomo tools to answer with real figures. A timeline shows each tool used. The agent works with the Anthropic key of the plugin settings and with the Anthropic provider of AI Providers.

To enable it, install, activate and enable McpServer in **Administration > System > General settings > McpServer**. The chat recommends each missing step, with a direct link for super users.

__Can the agent change things in Matomo?__

Only if McpServer allows write methods (Raw Matomo API tool access), and only with your confirmation: before any create, update or delete, the agent describes the exact change and waits for your explicit confirmation in the conversation. This rule cannot be removed by a custom prompt. The agent never has more access than the current user.

__Do I need to expose my Matomo instance or configure OAuth for the agent?__

No. The agent calls the Matomo tools inside your Matomo, with the permissions of the logged in user. It also works on private and intranet instances.

__Does the plugin work without the agent mode?__

Yes. Without McpServer, the chat and the insights answer without tools.

__What do insights analyse?__

The whole report you are looking at, not only the visible rows: its rows, totals and metrics, with the active segment, period and comparisons. Insights work on data tables, evolution graphs, goals, custom reports and the other reports Matomo declares as widgets.

__Can I use Claude together with the other Openmost AI plugins?__

Yes. Each plugin has its own button and page. Opening the insight panel of one plugin closes the panel of the others, so they never overlap.

__Can I customise the prompts?__

Yes, in the **Prompts** card of the general settings or of a website. The **Reset to default** button restores the default prompts, and on a website, **Use the general prompts** makes it follow the general prompts again. Custom prompts are kept when the defaults are improved.

__Is my data sent to Anthropic?__

Insights send the data of the report you are looking at (labels, metrics, totals, period, segment and comparisons) and the conversation to the configured endpoint: the Anthropic API by default, your custom host, or the Anthropic provider of AI Providers. In agent mode, the results of the tools the agent calls are sent too. Raw visitor data is never sent, unless the report itself contains it, such as the Visits Log. Conversations are not stored by the plugin.

__Is there a usage limit?__

Yes, 30 AI requests per hour, per user and per website, to protect your API budget.

__Which languages are supported?__

English, Arabic, Chinese (Simplified and Traditional), Dutch, French, German, Italian, Japanese, Polish, Portuguese, Spanish and Swedish.

__What are the requirements?__

- Matomo 5.10.0 or higher, below 6
- An Anthropic API key, or Anthropic connected in AI Providers (bundled with Matomo 5.13 and later)
- For the agent mode: the McpServer plugin (Matomo 5.8 or higher, PHP 8.1 or higher). On older setups, the chat keeps working without tools.

__How do I get support?__

Email ronan@openmost.com, or open an issue on [GitHub](https://github.com/openmost/Claude/issues). More on https://openmost.com/matomo/extensions/claude.
