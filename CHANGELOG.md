## Changelog

### 5.0.0

First release of Claude for Matomo 5.

**Chat and insights**

- A **Claude** page in the main menu, with streaming answers, suggested questions, copy buttons, scrollable tables and code blocks.
- An **Insights** button on any report: the whole report is analysed as a compact payload with its totals, following the active segment, period and comparisons, on data tables, evolution graphs, goals, custom reports and more.
- The insight panel closes the panel of the other Openmost AI plugins when it opens.

**Anthropic connection**

- Requests go to the Anthropic Messages API, with streaming, the system prompt as a top-level field and tool use for the agent.
- Preset models with **Latest recommended** as the default (currently Claude Sonnet 5.5), plus Claude Opus 5.5, Claude Fable 5.1, Claude Haiku 4.5 and the previous models Anthropic still serves, or any custom model ID.
- One key is enough, in this order: the API key of the website, then AI Providers when its provider is Anthropic, then the general API key. The credential of another AI Providers provider is never used. When Anthropic is connected in AI Providers, the general connection fields become read-only.
- An invalid key, a rate limit (with its retry delay), an overloaded API, a refused answer and an unavailable model are explained in the language of the user. Rate limits and overloads are retried a few times first.
- The host must be an HTTPS URL. The API key is optional on a custom host.

**Agent mode**

- With the McpServer plugin, the chat and the insights query the live reports through the Matomo tools, with the permissions of the current user, on the Anthropic key of the plugin or on AI Providers.
- Write actions are only performed after the agent has described the change and the user has confirmed it explicitly, whatever the base prompt says.
- The chat recommends, one step at a time, how to unlock the agent mode, with a direct link for super users.

**Settings**

- *Administration > System > Claude* for the general settings, with a *Connection* card and a *Prompts* card saved separately, a *Delete key* button and a *Reset to default* button for the prompts.
- *Administration > Websites > Claude* to override the settings of a website.
- Default prompts written for analytics, never stored, so their future improvements reach every install that did not customise them.
- The default prompts ask the assistant to flag the figures of a period that has not ended yet as partial and to compare the same number of elapsed days instead of calling a drop a decline, and to compute every difference, percentage and ratio from the exact numbers.

**More**

- Interface translated into 13 languages.
- Rate limit of 30 AI requests per hour, per user and per website.
- Insight requests are restricted to the report methods of the widgets declared by Matomo.

**Compatibility**

- Matomo 5.0.0 or higher, below 6: every Matomo theme variable used by the chat and the insight panel keeps the light theme value of Matomo as a fallback, so the releases without these variables show the light look.
