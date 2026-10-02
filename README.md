# Claude for Matomo

Ask questions about your analytics and get AI insights on any Matomo report with Claude by Anthropic, plus an optional agent that queries your live reports through the Matomo MCP tools.

## Features

### Insights on any report

- An **Insights** button in the report header opens an insight panel that analyses the report you are looking at, then lets you ask follow-up questions.
- The whole report is analysed, not only the rows visible on screen: the plugin sends a compact payload with the rows, the report totals and the names and units of the metrics.
- The analysis follows what you see: the active segment, the period and date, and the compared periods or segments.
- Works with data tables, evolution graphs, goals, ecommerce, custom dimensions, custom reports, row evolution and the other reports Matomo declares as widgets.
- When a widget has no report data, or the report cannot be loaded (for example a missing access), a clear message is displayed instead of sending the error to the model.
- The panel is an accessible dialog: keyboard navigation, focus kept inside the panel, Escape to close. Opening it closes the insight panel of the other Openmost AI plugins, so two panels never overlap.

### Chat

- A **Claude** page in the main menu, with suggested questions to start a conversation and a "New conversation" button.
- Streaming answers, with an automatic fallback when the endpoint does not stream.
- Auto-scroll that follows the answer while it is written, stops when you scroll up, and a button to jump back to the latest message.
- Markdown answers with scrollable tables and code blocks, and buttons to copy an answer or a code block.
- Enter sends the message, Shift+Enter adds a new line.

### Agent mode, with MCP Server

When the **McpServer** plugin is enabled, the chat and the insight panel work as an agent, with the Anthropic key of the plugin settings or with the Anthropic provider of **AI Providers**:

- The agent queries your live reports, websites, goals, dimensions and segments by itself, for any period, to answer with real figures.
- A timeline shows each Matomo tool used for an answer and its status.
- Tools run inside Matomo with the permissions of the current user: no public URL, OAuth client or extra token is needed.
- Write actions (for example creating a goal, an annotation or a segment) are only possible when McpServer allows write methods, and only after the agent has described the exact change and you have confirmed it explicitly in the conversation. This rule is added outside the editable prompts, so it cannot be removed by a custom prompt.
- When something is missing, the chat recommends the next step, one at a time: install, activate or enable MCP Server, allow write methods, and without an Anthropic key in the plugin settings, activate AI Providers and connect Anthropic. Super users get a direct link for each step, other users are asked to contact their Matomo administrator.

The agent mode is optional. Without McpServer, Claude answers without tools.

### Connection and models

- Uses the Anthropic Messages API (`https://api.anthropic.com/v1/messages`) with your Anthropic API key, or the **Anthropic** provider connected in **AI Providers**.
- **One key is enough**, used in this order: the API key set for the website, then AI Providers when its provider is Anthropic, then the general API key of the plugin. The credential of another AI Providers provider is never used. Keys are never copied from one place to another.
- When Anthropic is connected in AI Providers, it takes over: the host, API key and model of the general settings are displayed read-only, and are used again if AI Providers is deactivated or uses another provider. A key set for a website still overrides AI Providers for that website.
- The host can be changed to a proxy or gateway that serves the Anthropic Messages API over HTTPS. The API key is optional on a custom host.
- The general API key is only sent to the general host: a website using its own host never receives it.
- Preset models: **Latest recommended** (the default, which follows the model recommended by each plugin release, currently Claude Sonnet 5.5), Claude Sonnet 5.5, Claude Opus 5.5, Claude Fable 5.1, Claude Haiku 4.5 and the previous models Anthropic still serves, or any custom model name.
- When a model is deprecated, retired or not available to your Anthropic account, the chat explains it and links to the settings instead of showing the raw API error. An invalid key, a rate limit and an overloaded API are explained in the language of the user too.

### Prompts

- Default prompts written for analytics: the chat answers as a senior analytics consultant (direct answer, key figures, prioritised recommendations, no invented figures), and insights follow a fixed structure (summary, key figures, notable patterns, recommendations).
- A prompt equal to the default is not stored, so future improvements of the defaults reach you. A **Reset to default** button restores the default prompts. Custom prompts are kept.

### More

- Interface translated into 13 languages: English, Arabic, Chinese (Simplified and Traditional), Dutch, French, German, Italian, Japanese, Polish, Portuguese, Spanish and Swedish.
- Follows the Matomo light and dark themes.
- Rate limit of 30 AI requests per hour, per user and per website.
- HTTP API: `Claude.getResponse`, `Claude.getStreamingResponse`, `Claude.getInsights`, `Claude.getSiteSettings`, `Claude.setSiteSettings` and `Claude.setSystemSettings`.

## Requirements

- Matomo 6.x (`>=6.0.0-b1,<7.0.0-b1`)
- PHP 8.1 or higher
- One of: an Anthropic API key, an HTTPS proxy or gateway serving the Anthropic Messages API, or the Anthropic provider connected in AI Providers
- Optional, for the agent mode: the **McpServer** plugin from the Marketplace. Write actions also require write methods to be allowed in the McpServer settings (Raw Matomo API tool access).

## Installation / Configuration

1. Install and activate **Claude** from **Administration > Platform > Marketplace**.
2. As a super user, open **Administration > System > Claude**:
   - **Connection** card: host (an HTTPS URL, `https://api.anthropic.com` by default), API key and model. Each card is saved on its own. The saved key is never displayed, and a **Delete key** button removes it. Create an API key in the Claude Console (https://platform.claude.com).
   - **Prompts** card: chat and insight base prompts, with a **Reset to default** button.
   - If Anthropic is connected in **Administration > System > AI Providers**, the connection fields are read-only and AI Providers is used.
3. Optionally, override the settings for a website in **Administration > Websites > Claude** (website admin access): host, API key, model and prompts, with a **Delete key** button and a **Use the general prompts** button. Empty fields use the general settings.
4. For the agent mode, install, activate and enable **McpServer** (**Administration > System > General settings > McpServer**). The chat guides you through each missing step.

Then open the **Claude** page in the main menu, or click the **Insights** button in the header of a report.

## Privacy and data

- Insights send the data of the report you are looking at (labels, metrics and totals), its period, segment and comparisons, and the conversation, to the configured endpoint: the Anthropic API by default, your custom host, or the Anthropic provider of AI Providers.
- The chat sends your messages and the prompt. In agent mode, the results of the Matomo tools the agent calls are also sent to Anthropic.
- Raw visitor data is never sent, unless the report itself contains it, for example the Visits Log (limited to 100 visits).
- Insight requests are restricted to the report and data methods of widgets declared by Matomo, never to an arbitrary API method, and run with the permissions of the current user.
- API keys are stored in the Matomo settings and are never sent back to the browser. Conversations are not stored by the plugin.

## Need help with Matomo?

Openmost is an official Matomo Implementation Partner. We connect Matomo to AI assistants, BI tools and the rest of your stack with [Matomo integrations](https://openmost.com/matomo/services/integration?utm_source=matomo_marketplace&utm_medium=referral&utm_campaign=services&utm_content=claude) built on official APIs, with documented and privacy-checked data flows.

## Support

- Homepage: https://openmost.com/matomo/extensions/claude
- Email: ronan@openmost.com
- Issues: https://github.com/openmost/Claude/issues

## Screenshots

See the screenshots on the Marketplace page of the plugin.

## License

GPL v3+, developed by [Openmost](https://openmost.com). Claude and Anthropic are trademarks of Anthropic, PBC. This plugin is not affiliated with or endorsed by Anthropic.
