# Consumer Guide

How to use `MageOS_AiBase` from another module — making AI calls, reading configuration,
and handling failure. Audience: developers of modules that *use* AI services (product
description generation, translations, chat, ...). To *add* a provider, see
[PROVIDERS.md](PROVIDERS.md).

## Declare the dependency

```json
// composer.json
"require": { "mage-os/module-ai-base": "^1.0" }
```

```xml
<!-- etc/module.xml -->
<sequence><module name="MageOS_AiBase"/></sequence>
```

Type-hint only against `MageOS\AiBase\Api\*` interfaces and the `MageOS\AiBase\Exceptions\*`
classes that `chat()`, `complete()` and `streamChat()` throw (listed under
[Failure modes to handle](#failure-modes-to-handle)). Never depend on `Model\*` classes or on
symfony/ai types; implementations can be swapped by the host store via `<preference>`.
Everything below follows that rule: requests are assembled through
`Api\ChatRequestBuilderInterface`, and every `Api\Data` interface has a `<preference>`, so the
Magento-generated `*InterfaceFactory` for it resolves if you'd rather build one directly.

## What's stable

This module follows semantic versioning, and only types marked `@api`, plus
`AiServices\AbstractAiService`, are covered by it (Magento's coding standard keeps `@api` off
abstract classes). Anything
without `@api` (every `Model\*` class, `AiServices\*` traits, `ResourceModel\*`) can change in any
release.

| Type | Promise within a major version |
|---|---|
| `Api\*` and `Api\Data\*` interfaces | Safe to call and to type-hint against. Methods may be added in a minor release, so don't implement them yourself; write a plugin to change behavior. A store that replaces the whole client stack with a `<preference>` on `AiClientFactoryInterface` takes on adding those methods. |
| `Api\Data\AiServiceConfigurationInterface` | Safe to implement, by extending `AiServices\AbstractAiService`, which gets a default for every method added in a minor release. |
| Capability interfaces (`Api\ModelListProviderInterface`, `Api\ModelGuideProviderInterface`, `Api\PlatformArgumentsProviderInterface`) | Safe to implement. They don't change; a new capability gets a new interface. |
| The `bridges` argument of `Model\Client\BridgeRegistry` and the `dialects` argument of `Model\Client\OptionNormalizer` in di.xml | The keys documented in [PROVIDERS.md](PROVIDERS.md) don't change; new optional keys may be added. |
| `Exceptions\*` | Safe to catch. New subclasses may be added in a minor release, always below an existing one, so an existing `catch` keeps catching them. |
| Enums in `Api\Data` | Cases may be added in a minor release. Give a `match` over one a `default` arm. |
| JSON of `StreamChunkInterface`, `ToolCallInterface`, `TokenUsageInterface` | Keys don't change or disappear. New keys may be added. |

## Making AI calls (recommended)

```php
use Magento\Framework\Exception\LocalizedException;
use MageOS\AiBase\Api\AiClientFactoryInterface;

class DescriptionGenerator
{
    public function __construct(
        private readonly AiClientFactoryInterface $aiClientFactory,
    ) {
    }

    public function generate(string $productName): string
    {
        $client = $this->aiClientFactory->create();          // first configured service
        // ...or a specific backend: $this->aiClientFactory->create('anthropic');

        return $client->complete(
            'Write a product description for: ' . $productName,
            ['max_tokens' => 400],                            // see "Options" below
        );
    }
}
```

`complete()` is single-turn prompt-in/text-out, a convenience over `chat()`.
A client also says what it is, for logging and cost attribution: `getServiceCode()` (`openai`),
`getServiceId()` (the configured row, which is what separates two accounts on the same backend)
and `getModel()` (`gpt-4o`). Cost is per model, so a usage log wants all three.

### Naming your module as a consumer

Every call a client makes is recorded (see [docs/USAGE-TRACKING.md](USAGE-TRACKING.md)) under a
**consumer**: an identifier for the module or feature that made it. Name yours instead of leaving
every call attributed to `unknown`, since that is the only thing that lets a merchant tell "the
product description generator" apart from "the support chat" in the usage dashboard.

There are two ways to set it, and they answer two different questions:

```php
use MageOS\AiBase\Api\AiClientFactoryInterface;
use MageOS\AiBase\Api\AiClientInterface;

// Every call this client ever makes is attributed to "catalog_description_generator",
// unless a single call overrides it (see below).
$client = $this->aiClientFactory->create(consumer: 'catalog_description_generator');

$client->complete('Write a product description for: ' . $productName);

// One call, on a client built for something broader, attributed differently.
$client->complete(
    'Summarize these reviews: ' . $reviewText,
    [AiClientInterface::OPTION_CONSUMER => 'review_summarizer'],
);
```

`create()` and `createById()` both take `$consumer` as an optional, trailing argument, so every
call site written before it existed still compiles unchanged. Use it when one client, built once,
always serves the same feature. `AiClientInterface::OPTION_CONSUMER` is a per-call option instead,
for a client that is shared across features and needs to tell them apart without building a second
client for each one; it overrides the client-level consumer for that one call only and is never
sent to the provider. `getConsumer()` on the client reads back whichever value the factory was
given, or `Api\Data\UsageRecordInterface::CONSUMER_UNKNOWN` when neither this argument nor the
per-call option was ever set.

## Options

Six options are provider-neutral and get translated to whatever the configured backend calls
them, so the same code works whichever one an administrator picked:

| Option | Notes |
|---|---|
| `max_tokens` | `max_output_tokens` on OpenAI/Azure, `maxOutputTokens` on Google, `num_predict` on Ollama. Anthropic *requires* it; the client sends 4096 when you don't. |
| `temperature` | Same name everywhere. |
| `top_p` | `topP` on Google. |
| `stop` | `stop_sequences` on Anthropic, `stopSequences` on Google; a single string is wrapped into a list where the provider wants one. **Not supported on OpenAI and Azure**, whose Responses API has no such parameter — passing it there throws rather than being dropped. |
| `tool_choice` | `auto`, `none`, `required`, or `['tool' => '<name>']` to force one specific tool. Each value is translated too, not only renamed: Anthropic's "required" is `any`. **Not supported on Ollama** — `auto` is a no-op there since it is every provider's own default, anything else throws. |
| `reasoning_effort` | `none`, `low`, `medium` or `high`. Spreads across more than one request field on some providers (Anthropic sets both `thinking` and `output_config`). |

For `tool_choice` and `reasoning_effort`, only the values listed above are translated. Any other
value is taken to be the provider's own and sent as written, so code already forcing a tool in
Anthropic's shape (`['type' => 'tool', 'name' => 'get_orders']`) or asking OpenAI for `minimal`
effort keeps working. Some of the translated values are only accepted by some models of a
provider, not all of them; see
[PROVIDERS.md](PROVIDERS.md#model-dependent-values) before relying on `reasoning_effort: none` or a
forced tool on Anthropic or Gemini.

Anything else is passed through to the provider untouched, so provider-specific features stay
reachable (Anthropic's `thinking`, Ollama's `keep_alive`, ...). That is the escape hatch for code
that has deliberately picked its backend; it is not portable, by definition.

## Conversations, tools and streaming

Build a request with `ChatRequestBuilderInterface`. Inject its generated factory and start one
per call — every method returns a new builder, so a builder holding your common preamble can be
kept and branched from:

```php
use MageOS\AiBase\Api\AiClientFactoryInterface;
use MageOS\AiBase\Api\ChatRequestBuilderInterfaceFactory;

public function __construct(
    private readonly AiClientFactoryInterface $aiClientFactory,
    private readonly ChatRequestBuilderInterfaceFactory $chatRequestBuilderFactory,
) {
}

$request = $this->chatRequestBuilderFactory->create()
    ->withSystemMessage('You are a Magento support assistant.')
    ->withUserMessage('Which orders are still pending?')
    ->withTool('get_orders', 'Lists orders by status', [
        'type' => 'object',
        'properties' => ['status' => ['type' => 'string']],
    ])
    ->build();

$response = $this->aiClientFactory->create()->chat($request);
$response->getText();             // assistant text, empty when it only asked for tools
$response->getToolCalls();        // ToolCallInterface[]
$response->getUsage();            // TokenUsageInterface, or null
$response->getFinishReason();     // FinishReason enum, or null
```

`getUsage()` returns a `TokenUsageInterface`, or `null` when the provider reported nothing at
all, with six independently nullable counts: `getPromptTokens()`, `getCompletionTokens()`,
`getTotalTokens()`, `getCacheReadTokens()`, `getCacheWriteTokens()` and `getReasoningTokens()`.
The prompt count **already includes whatever the provider served from cache** on every provider,
even the one whose own API defines its prompt count as excluding it (Anthropic); adding a cache
count on top of the prompt count to get "total input" double-counts it. Cache read and cache write
are two separate subsets of the prompt count, never additions to it, since providers typically bill
the two at different rates. See [docs/USAGE-TRACKING.md](USAGE-TRACKING.md#token-counts) for the
full breakdown, and its [provider table](USAGE-TRACKING.md#which-providers-report-what) for which
providers report which count.

`getFinishReason()` is normalized across providers, because the same event is `length` at
OpenAI, `max_tokens` at Anthropic and `MAX_TOKENS` at Google. `FinishReason::Length` is the one
worth handling everywhere — it means the text is a truncated answer rather than a finished one,
and nothing else in the response says so:

```php
if ($response->getFinishReason() === FinishReason::Length) {
    // the answer is cut off: raise max_tokens, or ask for something shorter
}
```

`getRawFinishReason()` keeps the provider's own wording, for logs and support tickets.

> **One reserved pair of argument names.** A tool argument may not be named `instance` or
> `argument`. The schema reaches the tool definition through a Magento-generated factory, and the
> ObjectManager walks array arguments looking for DI references: a nested object holding an
> `instance` key is resolved as a service (raising a `TypeError`), and one holding an `argument`
> key is replaced by a global argument (silently nulling the surrounding object). Every other
> name, at every nesting level, passes through untouched. Rename the argument, or build the
> definition yourself and pass it to `withToolDefinition()` — an already-built object is not
> walked.

### The tool loop

**This module never executes tools.** It reports what the model asked for and carries your
result back. Whether a call may run is your policy (user confirmation for write actions, a
Magento ACL per tool, per-tool instructions), and that does not generalise.

```php
for ($i = 0; $i < $maxIterations; $i++) {
    $response = $client->chat($request);

    if (!$response->hasToolCalls()) {
        return $response->getText();
    }

    $request = $request->withAssistantTurn($response);

    foreach ($response->getToolCalls() as $call) {
        // your ACL check, your confirmation gate, your tool registry
        $result = $this->myToolRegistry->run($call->getName(), $call->getArguments());
        $request = $request->withToolResult($call, json_encode($result));
    }
}
```

`ChatRequestInterface` is immutable, so each iteration gets a fresh request and nothing leaks
into one a caller still holds. Two methods carry the two halves of a turn that are easy to get
wrong: `withAssistantTurn()` puts the model's own message back with its tool calls and any
reasoning blocks attached (append the text and forget the calls, and the provider rejects results
answering calls it cannot see; drop a required reasoning block and a provider that demands it back
rejects the whole turn instead), and `withToolResult()` binds each result to the call that
produced it.

Reasoning belongs to the service that produced it. Its signature is only meaningful to that
provider, and the Anthropic and Gemini bridges pass a foreign one on as if it were their own,
which the provider is likely to reject. If you store a transcript and replay it later, drop the
reasoning from stored assistant turns whenever the service has changed since, for instance
because an administrator picked a different one in between. Replaying to the same service is
what it is for.

### Streaming

`streamChat()` returns a `\Generator`, so you drive the loop and may stop early:

```php
foreach ($client->streamChat($request) as $chunk) {
    match ($chunk->getType()) {
        StreamChunkType::Text          => $this->emit($chunk->getText()),
        StreamChunkType::Thinking      => null,                    // reasoning, not the answer
        StreamChunkType::ThinkingStart => $this->emit('thinking…'),
        StreamChunkType::ToolCall      => $calls[] = $chunk->getToolCall(),
        StreamChunkType::ToolCallStart => $this->announceToolCall($chunk->getToolCall()),
        StreamChunkType::Usage         => $usage = $chunk->getUsage(),
    };
}

private function announceToolCall(ToolCallInterface $toolCall): void
{
    if (isset($this->announcedToolCallIds[$toolCall->getId()])) {
        return;
    }

    $this->announcedToolCallIds[$toolCall->getId()] = true;
    $this->emit('searching ' . $toolCall->getName());
}
```

A `match` with no default arm throws `UnhandledMatchError` the moment a new
`StreamChunkType` case ships, which is exactly what happened when `ThinkingStart` and
`ToolCallStart` were added; add a default arm (`default => null`) if you would rather ignore
chunk kinds you do not yet handle than update this `match` on every release.

Every chunk is `JsonSerializable`, so sending the stream to a browser as server-sent events is
one line per chunk:

```php
foreach ($client->streamChat($request) as $chunk) {
    echo 'data: ' . json_encode($chunk) . "\n\n";
    flush();
}
```

The JSON shape is stable across 1.x:

| Chunk type | JSON |
|---|---|
| `text`, `thinking` | `{"type": "text", "text": "Hel"}` |
| `thinking_start` | `{"type": "thinking_start"}` |
| `tool_call`, `tool_call_start` | `{"type": "tool_call", "tool_call": {"id": "toolu_01", "name": "get_orders", "arguments": {"status": "pending"}}}` |
| `usage` | `{"type": "usage", "usage": {"prompt_tokens": 120, "completion_tokens": 45, "total_tokens": 165, "cache_read_tokens": null, "cache_write_tokens": null, "reasoning_tokens": null}}` |

`arguments` is always an object, `{}` on a `tool_call_start` chunk. A tool call and a usage
object encode the same way on their own, so `json_encode($response->getToolCalls())` gives the
same shape for a buffered response.

Tool calls arrive **complete**, with arguments already accumulated and JSON-decoded by the
provider bridge, on a `StreamChunkType::ToolCall` chunk. There are no SSE frames to parse and
no `input_json_delta` fragments to stitch together. A turn requesting several tools yields one
chunk per call, so `getToolCall()` always means exactly one.

On a provider that reports it, a `StreamChunkType::ThinkingStart` or `StreamChunkType::ToolCallStart`
chunk arrives as soon as the model opens that block, before it has written anything: the pause
where a customer would otherwise stare at an empty box. `ThinkingStart` carries no payload.
`ToolCallStart` carries the id and name of the call the model is opening, with `getToolCall()`
returning empty arguments; it may repeat more than once for the same id while the provider streams
the arguments, so treat it as "a call named X is under way", not as a second, separate call, and
key anything you show the customer on the id, as `announceToolCall()` above does. The
completed call, arguments included, still arrives exactly once as before, on its own
`StreamChunkType::ToolCall` chunk. A provider that does not report either signal simply never
yields that chunk kind; nothing about the rest of the stream changes.

#### The turn a stream produced

When the stream runs to completion the generator **returns** the same `ChatResponseInterface`
a buffered `chat()` would have given you — text concatenated, tool calls collected, final token
counts and stop reason attached. A streaming tool loop needs exactly that to append before the
next iteration, so there is no accumulator to write:

```php
$stream = $client->streamChat($request);

foreach ($stream as $chunk) {
    if ($chunk->getType() === StreamChunkType::Text) {
        $this->emit($chunk->getText());
    }
}

$turn = $stream->getReturn();            // ChatResponseInterface
$request = $request->withAssistantTurn($turn);

foreach ($turn->getToolCalls() as $call) {
    $request = $request->withToolResult($call, json_encode($this->myToolRegistry->run(
        $call->getName(),
        $call->getArguments(),
    )));
}
```

`getReturn()` is only valid once the generator has finished. Breaking out of the loop early
raises an `\Exception` from PHP, which is the correct signal: there is no complete turn to
append.

#### A failing stream still reports usage

When a stream breaks partway through, a dropped connection, a provider-side error mid-response,
`streamChat()` does not swallow what was already billed into the exception. It yields one final
`StreamChunkType::Usage` chunk carrying whatever the platform had reported up to that point, then
raises the mapped typed exception (see [Typed exceptions](#typed-exceptions) below) from the
generator's next iteration, exactly like a failure from `chat()` or `complete()` would:

```php
$usage = null;

try {
    foreach ($client->streamChat($request) as $chunk) {
        if ($chunk->getType() === StreamChunkType::Usage) {
            $usage = $chunk->getUsage();
        }
        // ... handle Text/ToolCall chunks as usual
    }
} catch (LocalizedException $e) {
    // $usage holds whatever was billed before the failure, or still null if nothing was
}
```

**Keep iterating the loop to see the exception.** A `foreach` that stops on the usage chunk, or
exits early for any other reason, never reaches the iteration that raises it. This is also what
lets the usage-recording decorator log a failed streamed call's partial usage instead of losing it:
it is sitting in the chunk sequence, not only in the exception. See
[docs/USAGE-TRACKING.md](USAGE-TRACKING.md#failed-calls-and-null-token-rows) for how that row is
recorded.

#### A truncated stream ends normally, not as an exception

When a provider cuts a stream off because it hit the configured output token limit, that is not
raised as an exception at all. The generator ends the same way a complete stream does: `getReturn()`
carries the text collected so far and reports `FinishReason::Length`, exactly as a non-streamed
`chat()` call reports a truncated answer (see `getFinishReason()` under
[Conversations, tools and streaming](#conversations-tools-and-streaming) above).
On Anthropic, `getRawFinishReason()` is `null` on a truncated stream: the bridge throws at
`message_stop`, before the delta that carries the stop reason, so only the normalized
`FinishReason::Length` is available there. A buffered `chat()` call is unaffected.

### Failure modes to handle

`create()` throws `LocalizedException` for setup problems (no service configured, no bridge
registered, the provider's Symfony AI bridge not installed) with admin-readable messages. `chat()`,
`complete()` and `streamChat()` throw one of this module's own typed exceptions, every one of
which extends `LocalizedException`, so an existing `catch (LocalizedException)` still catches
everything without any change:

| Condition | When | Exception |
|---|---|---|
| No service configured (at all, or for the requested code) | `create()` | `LocalizedException` |
| No client bridge registered for the service code | `create()` | `LocalizedException` |
| The provider's Symfony AI bridge package not installed | `create()` | `LocalizedException` |
| A call rejected before it ever reached the provider: an unsupported option, an invalid model override, one the service's bridge has no route to, or any override on Azure (which always runs its configured deployment), a tool result message missing its call id | `chat()` / `complete()` / `streamChat()` | `AiRequestNotSentException` |
| The provider rejected the configured credentials | `chat()` / `complete()` / `streamChat()` | `AiAuthenticationException` |
| The provider throttled the call | `chat()` / `complete()` / `streamChat()` | `AiRateLimitedException` (`getRetryAfter(): ?int`) |
| A server error, an overloaded model, a network failure (connection refused, DNS failure, connection reset, timeout), or a stream that ended before reporting completion | `chat()` / `complete()` / `streamChat()` | `AiTransientException` |
| A bad request, a prompt over the context window, or an unknown model | `chat()` / `complete()` / `streamChat()` | `AiInvalidRequestException` |
| The provider's safety filter refused to answer | `chat()` / `complete()` / `streamChat()` | `AiContentFilteredException` (extends `AiInvalidRequestException`) |
| The model's tool call arguments could not be parsed as JSON | `chat()` / `complete()` / `streamChat()` | `AiToolCallException` |
| Any other provider or library failure | `chat()` / `complete()` / `streamChat()` | `AiServiceException` |

Treat all of these as recoverable: catch `LocalizedException` (or a specific subtype),
degrade gracefully (skip the AI feature, queue for retry, surface the message to the admin).
Don't let an unconfigured AI backend break checkout or a cron run. The `create()` messages are
actionable by design — the "not installed" one includes the composer command — so surfacing
them in admin UIs is usually the right move.

`AiRequestNotSentException` is worth telling apart from every other row in the table above:
nothing was ever sent to the provider, so nothing was billed, and usage tracking never writes a
row for it either, see
[docs/USAGE-TRACKING.md](USAGE-TRACKING.md#failed-calls-and-null-token-rows).

### Typed exceptions

`AiServiceException` is the base of every exception a reached-the-provider call can throw; catching
it (or `LocalizedException`) catches all of them, the same way it always has. Catch a subtype to
react differently per failure:

```php
use MageOS\AiBase\Exceptions\AiAuthenticationException;
use MageOS\AiBase\Exceptions\AiInvalidRequestException;
use MageOS\AiBase\Exceptions\AiRateLimitedException;
use MageOS\AiBase\Exceptions\AiServiceException;
use MageOS\AiBase\Exceptions\AiTransientException;

try {
    $response = $client->complete($prompt);
} catch (AiRateLimitedException $e) {
    $this->scheduleRetry($e->getRetryAfter() ?? 30);
} catch (AiTransientException $e) {
    $this->scheduleRetry();
} catch (AiAuthenticationException|AiInvalidRequestException $e) {
    $this->logger->error($e->getMessage());   // retrying the same request will not help
} catch (AiServiceException $e) {
    $this->logger->error($e->getMessage());
}
```

Each type is built by mapping the underlying symfony/ai-platform exception class, so what a
consumer can distinguish is bounded by what the bridge in use actually reports:

- **Ollama and HuggingFace** do not report a type at all: Ollama's bridge does not check the HTTP
  status on `/api/chat`, and HuggingFace's turns a 401 or a 429 into a generic
  `InvalidArgumentException`. Every failure from either stays `AiServiceException` with the
  provider's own message.
- **Azure, OpenRouter and LM Studio** report the failure type (so `AiRateLimitedException` and the
  others are reachable), but not a wait time, so `getRetryAfter()` returns `null` on all three.

An exception this module does not recognize is not swallowed or left untyped either: it still
comes back as `AiServiceException`, with the original kept as `getPrevious()`.

### Which service will `create()` use?

- `create()` (no argument): the **first configured service whose bridge is installed**, in
  the order the admin saved them.
- `create('openai')`: the **first configured row** with that code. Admins can configure
  the same backend multiple times; to reach a specific row, let the admin pick one and use
  `createById()` (below).
- `createById('_1712345678901_901')`: the **one row** carrying that id, whichever position
  it occupies.

## Letting the admin pick a service

Rather than hardcoding a service code, give your module's own configuration a select field
backed by this module's option source. It lists every service the admin configured, labelled
by provider and model:

```xml
<!-- your module's etc/adminhtml/system.xml -->
<field id="ai_service" translate="label" type="select" sortOrder="10"
       showInDefault="1" showInWebsite="1" showInStore="1">
    <label>AI service</label>
    <source_model>MageOS\AiBase\Model\Config\Source\ConfiguredService</source_model>
</field>
```

Two source models are available:

| Source model | Options |
|---|---|
| `Model\Config\Source\ConfiguredService` | One per configured row |
| `Model\Config\Source\ConfiguredServiceWithAutomatic` | The same, preceded by an empty-valued *Automatic (first usable service)* option |

Labels read `OpenAI (gpt-4o)`. Two rows that would otherwise be identical are numbered
(`OpenAI (gpt-4o) #2`). A row whose Symfony AI bridge is missing stays selectable but says so
(`Ollama (llama3, bridge not installed)`), because a module calling the provider with its own
HTTP client does not need a bridge.

The **stored value is the row id**, not the service code, since the code cannot tell two rows
of the same provider apart. Turn that stored id into a client with `createById()`:

```php
use Magento\Framework\App\Config\ScopeConfigInterface;
use MageOS\AiBase\Api\AiClientFactoryInterface;

$serviceId = (string) $this->scopeConfig->getValue('my_module/ai/ai_service', ScopeInterface::SCOPE_STORE);

$client = $serviceId === ''
    ? $this->aiClientFactory->create()            // "Automatic", or nothing chosen yet
    : $this->aiClientFactory->createById($serviceId);
```

Or read its raw configuration with `AiServiceSelectorInterface::getById()`, which returns
`null` when the admin has since deleted that row:

```php
$service = $this->aiServiceSelector->getById($serviceId);
if ($service === null) {
    // The row is gone. Fall back, or tell the admin to pick again.
}
```

`createById()` throws `LocalizedException` in that same situation rather than silently falling
back to another row: another row means another account and another bill, which is not a
substitution to make on the admin's behalf.

### Restricting the choice to specific providers

A feature that only works with one provider (a Gemini-specific API, an OpenAI-only endpoint) should
not let the admin point it at a row of another provider. Declare a virtual type over either source
model with the `serviceCodes` argument set, and point your field at the virtual type.

The argument exists since ai-base 1.1.0, so require `"mage-os/module-ai-base": "^1.1"`. On an
older release Magento's object manager drops the unknown argument without an error, and the
dropdown quietly lists every provider again.

```xml
<!-- your module's etc/di.xml -->
<virtualType name="Vendor\Module\Model\Config\Source\GeminiService"
             type="MageOS\AiBase\Model\Config\Source\ConfiguredService">
    <arguments>
        <argument name="serviceCodes" xsi:type="array">
            <item name="google" xsi:type="string">google</item>
        </argument>
    </arguments>
</virtualType>
```

```xml
<!-- your module's etc/adminhtml/system.xml -->
<field id="ai_service" translate="label" type="select" sortOrder="10"
       showInDefault="1" showInWebsite="1" showInStore="1">
    <label>Gemini service</label>
    <source_model>Vendor\Module\Model\Config\Source\GeminiService</source_model>
</field>
```

The dropdown then lists only that provider's rows. Several codes are allowed, for a feature that
works with any of a few providers. The codes are the ones `AiServiceInterface::getCode()` returns:
`openai`, `anthropic`, `azure`, `google`, `deepseek`, `huggingface`, `lmstudio`, `ollama`,
`openrouter`, `openai_compatible`.

When the admin has not configured any row of those providers, the dropdown offers one empty-valued
option that says so: *No Google Gemini service configured yet. Add one under Stores >
Configuration > Mage-OS > AI Configuration.* The stored value is then `''`, the same "nothing
chosen" your code already handles. The `WithAutomatic` variant skips that placeholder, since its
*Automatic* option already carries the empty value; resolve *Automatic* there with
`create('google')` rather than `create()`, so the fallback stays within the provider the feature
needs, and gets a `LocalizedException` naming the missing provider instead of a row of another one.

The filter is an argument rather than a shipped virtual type per provider because the useful set is
open: a module may accept both `openai` and `azure`, or treat a store's `openai_compatible` gateway
as good enough.

The dropdown is the only thing the filter touches. A row id already stored in your field stays
stored, and `getById()` / `createById()` keep resolving it whatever its provider. Two consequences
when you restrict a field that has shipped unrestricted before:

- A stored row of another provider is no longer in the dropdown, so the field renders with the
  first option selected, and the next Save of that config section writes that option. The feature
  keeps using the old row until then.
- Nothing stops the stored row from being the wrong provider in between. If the feature breaks on
  the wrong provider, check `getCode()` on the resolved service and treat a mismatch like an unset
  field.

## Moving your module's own credentials into AI Base

A module that kept its own API key fields before it used this one can move the saved key over
in a data patch, so the merchant doesn't have to find it and type it in again (an `obscure`
field can't be read back to copy it anyway). `Api\ServiceImporterInterface` adds an ordinary
row to the AI Configuration form and returns its id:

```php
ServiceImporterInterface::import(string $serviceCode, array $configuration, ?string $label = null): string
ServiceImporterInterface::importFromConfig(string $serviceCode, array $fieldPaths, ?string $label = null): ?string
```

- `import()` takes plain values (`['api_key' => 'sk-...', 'model' => 'gpt-4o']`).
  `importFromConfig()` takes a map of this module's field name to **your** config path, reads
  each one at default scope, and decrypts the ones this module stores encrypted when they hold
  a Magento ciphertext (what an `obscure` field with the `Encrypted` backend model stores). A
  plaintext value, such as a key saved before your field was encrypted, is used as it is.
- `importFromConfig()` returns `null` and adds nothing when the credential is empty, so the
  patch doesn't need its own check.
- The row is added at default scope, enabled, with its credentials encrypted the same way the
  form encrypts them. The administrator sees and edits it like any other row.
- Only fields the provider declares are accepted (`api_key`, `model`, `base_url`, ...; see
  [PROVIDERS.md](PROVIDERS.md)). An unknown field name throws instead of being dropped, so a
  typo can't quietly import a row without its key. Leaving `model` out is fine.
- Importing the same values again returns the id of the row that already holds them, so a
  patch that runs twice, or two modules moving the same key, end up with one row.
- It throws a `LocalizedException` when the service code isn't registered, when the AI services
  are pinned in `app/etc/env.php` / `config.php`, or when an administrator saved the AI
  Configuration form at the same moment. In a data patch that fails `setup:upgrade`, which is
  usually what you want; catch it if your module can keep running on its old fields.

A complete patch, storing the row id in the module's own `ConfiguredService` select (see
[Letting the admin pick a service](#letting-the-admin-pick-a-service)) and removing the old key
afterwards:

```php
<?php

declare(strict_types=1);

namespace Vendor\Translations\Setup\Patch\Data;

use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use MageOS\AiBase\Api\ServiceImporterInterface;

class MoveOpenAiKeyToAiBase implements DataPatchInterface
{
    private const OLD_API_KEY_PATH = 'vendor_translations/engine/openai_api_key';
    private const OLD_MODEL_PATH = 'vendor_translations/engine/openai_model';
    private const AI_SERVICE_PATH = 'vendor_translations/engine/ai_service';

    public function __construct(
        private readonly ServiceImporterInterface $serviceImporter,
        private readonly WriterInterface $configWriter,
    ) {
    }

    public function apply(): self
    {
        $rowId = $this->serviceImporter->importFromConfig(
            'openai',
            ['api_key' => self::OLD_API_KEY_PATH, 'model' => self::OLD_MODEL_PATH],
            'Translations',
        );
        if ($rowId === null) {
            return $this;
        }

        $this->configWriter->save(self::AI_SERVICE_PATH, $rowId);
        $this->configWriter->delete(self::OLD_API_KEY_PATH);
        $this->configWriter->delete(self::OLD_MODEL_PATH);

        return $this;
    }

    public static function getDependencies(): array
    {
        return [];
    }

    public function getAliases(): array
    {
        return [];
    }
}
```

Then remove the old fields from your `system.xml` and stop reading them; from here on the key
lives only in AI Base, and your code resolves `AI_SERVICE_PATH` through `createById()`. Only
the default-scope value is moved: if your old field was editable per website or store, those
values stay where they are and are yours to handle. Keep `MageOS_AiBase` in your
`module.xml` `<sequence>` (see [Declare the dependency](#declare-the-dependency)) so its
configuration is in place when your patch runs.

## Reaching the platform directly (escape hatch)

`AiClientInterface` covers chat, streaming and single-turn completion. symfony/ai-platform does a
great deal more: executed tool loops via `symfony/ai-agent`, message stores and sessions via
`symfony/ai-chat`, structured output, embeddings, vector stores, image and audio. Mirroring all of
that here would mean re-describing an API that already exists, so instead this module hands over
the platform it already built: credentials resolved, bridge selected, row chosen by the admin.

> **symfony/ai-platform is experimental.** Experimental features are not covered by Symfony's
> [Backward Compatibility Promise](https://symfony.com/doc/current/contributing/code/bc.html).
> This is not hypothetical on the surface a tool loop touches most: `Message::ofAssistant()` was
> reworked in 0.9 and `Message::ofToolCall()` in 0.11. Code written against `Api\*` is insulated
> from that by the adapter behind it. Code written against symfony/ai types is not, and has to be
> re-verified on every upgrade. Pin the version.

```php
use MageOS\AiBase\Api\AiClientFactoryInterface;
use MageOS\AiBase\Api\PlatformAwareInterface;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;

$client = $this->aiClientFactory->createById($serviceId);

if (!$client instanceof PlatformAwareInterface) {
    return $client->complete($prompt);      // no platform to reach; use the stable surface
}

$result = $client->getPlatform()->invoke(
    $client->getModel(),
    new MessageBag(Message::ofUser($prompt)),
    $client->normalizeOptions(['max_tokens' => 400]),
);
```

Three things to know:

- **The `instanceof` check is the API.** It makes the coupling deliberate and keeps it optional:
  a store that preferences its own client stack does not implement the interface, and your code
  degrades to the stable surface instead of fataling.
- **`getModel()` is the model name to invoke with.** It comes off `AiClientInterface`, and it is
  the model the administrator configured on that row.
- **Keep `normalizeOptions()`.** Calling the platform directly otherwise opts you out of every
  translation described under [Options](#options), including the `max_tokens` Anthropic requires.
  It returns the same array `chat()` would have sent.

### With symfony/ai-agent

The agent component runs the tool loop for you, tools included, which is the main reason to
reach past `AiClientInterface`:

```bash
composer require symfony/ai-agent
```

```php
use Symfony\AI\Agent\Agent;
use Symfony\AI\Agent\Toolbox\AgentProcessor;
use Symfony\AI\Agent\Toolbox\Toolbox;

$toolbox = new Toolbox([$this->myOrderTool]);
$processor = new AgentProcessor($toolbox);

$agent = new Agent($client->getPlatform(), $client->getModel(), [$processor], [$processor]);

$answer = $agent->call('Which orders are still pending?')->getContent();
```

`AgentProcessor` is passed as both input and output processor: it advertises the tools on the way
out and resolves the calls on the way back. It caps itself at 50 tool calls per turn by default
and throws `MaxIterationsExceededException` past that.

Note the trade this makes. This module never executes tools, deliberately: whether a call may run
is policy that belongs to the module owning the tool (a confirmation gate for write actions, a
Magento ACL per tool), and none of it generalises. `Toolbox` **does** execute them. Reaching for
the agent component means taking that policy decision on yourself, which is where it belonged
anyway; just take it knowingly rather than by accident.

## Reading raw configuration (lower level)

When you need credentials/values directly — e.g. you're calling a provider API the client
layer doesn't cover:

```php
use MageOS\AiBase\Api\AiServiceSelectorInterface;

$services = $this->aiServiceSelector->getByCode('openai');   // AiServiceInterface[]
foreach ($services as $service) {
    $config = $service->getConfiguration();
    // ['api_key' => '...', 'model' => 'gpt-4o', ...] — credentials already decrypted
}
```

Notes:

- Values are decrypted for you; **never log or persist them**, and never echo them to any
  frontend or admin response.
- Field names are snake_case: `api_key`, `model`, `base_url`, `endpoint`, `api_version`.
- `getId()` is the row's stable identity, and the only thing that separates two rows of the
  same provider. It is what the option source stores and what `getById()` resolves.
- An empty array means nothing is configured — expected state on fresh installs; handle it.
- A row an administrator **disabled** is never returned, by any lookup. It keeps its id and
  credentials and stays in the admin form, but nothing may call it, so callers don't have to
  check. `getLabel()` returns the name the administrator gave the row (`'Chat AI'`), or null;
  `isEnabled()` is always true for anything the selector hands you.
- Configuration is read at store scope, in whatever scope is ambient at the moment you call.
  In a storefront request that is the current store, so a per-store setup resolves on its own.
  **In adminhtml, in cron and on the CLI there is no current store**, so the default scope
  answers and a per-website or per-store services list is not reachable from those contexts.
  The selector takes no scope argument: code that needs a specific scope has to establish it
  first (store emulation), the same rule the rest of Magento's configuration follows.

## Extending behavior

Both consumer interfaces are DI-served, so standard Magento extension applies:

- **Plugin** on `AiClientInterface::complete()` for logging, token accounting, redaction,
  or prompt policy — remember plugins see prompts and responses, treat them as sensitive.
- **Preference** on `AiClientFactoryInterface` to substitute the entire client stack.

## Testing your consumer

Both interfaces mock cleanly with PHPUnit:

```php
$client = $this->createMock(AiClientInterface::class);
$client->method('complete')->willReturn('A fine description.');
$factory = $this->createMock(AiClientFactoryInterface::class);
$factory->method('create')->willReturn($client);
```

Also test the unconfigured path: `$factory->method('create')->willThrowException(
new LocalizedException(__('No AI service configured')))` — your feature should degrade,
not fatal.
