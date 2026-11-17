<?php

declare(strict_types=1);

namespace MageOS\AiBase\Block\Adminhtml\Configuration;

use Magento\Backend\Block\Template\Context;
use Magento\Config\Block\System\Config\Form\Field\FieldArray\AbstractFieldArray;
use Magento\Config\Model\Config\Reader\Source\Deployed\SettingChecker;
use Magento\Framework\App\State;
use Magento\Framework\DataObject;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\View\Helper\SecureHtmlRenderer;
use MageOS\AiBase\Api\Data\AiServiceConfigurationInterface;
use MageOS\AiBase\Api\Data\AiServiceInterface;
use MageOS\AiBase\Api\Data\FieldDescriptorInterface;
use MageOS\AiBase\Api\ModelListProviderInterface;
use MageOS\AiBase\Model\AiServiceSelector;
use MageOS\AiBase\Model\Client\BridgeRegistry;
use MageOS\AiBase\Model\Config\ConfigScope;
use MageOS\AiBase\Model\Config\ConfigScopeResolver;
use MageOS\AiBase\Model\ModelList\Resolver;
use MageOS\AiBase\Model\ServiceRegistry;

class Services extends AbstractFieldArray
{
    /**
     * Flags for every JSON value this block hands to the template's inline script.
     *
     * The script is parsed as HTML before it is ever run as JavaScript, and in a script element the
     * HTML parser looks for `</script>` and, after a `<!--`, for `<script`. Stored rows and the model
     * names a provider returned from Refresh Models are not ours, so a value such as `<!--<script>`
     * put the parser into the script-data double-escaped state, the real closing tag was swallowed,
     * the script never ran and no rows rendered. Hex-escaping `<`, `>`, `&`, `'` and `"` inside
     * strings leaves nothing the HTML parser can react to, and JavaScript reads `\u003C` back as `<`,
     * so the values the script sees are unchanged.
     */
    public const SCRIPT_JSON_FLAGS = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
        | JSON_THROW_ON_ERROR;

    /**
     * Name of the field that holds a row's model, whose options are the row's resolved model list.
     */
    private const FIELD_MODEL = 'model';

    /**
     * @var string
     */
    protected $_template = 'MageOS_AiBase::system/config/form/field/services.phtml';

    /**
     * @param Context $context
     * @param ServiceRegistry $serviceRegistry Registered backends; it validates its own entries
     * @param Resolver $modelListResolver
     * @param BridgeRegistry $bridgeRegistry
     * @param SettingChecker $settingChecker The check Magento's save path uses to skip a field set
     *        in deployment configuration
     * @param ConfigScopeResolver $scopeResolver Turns the rendered field's scope id into a code
     * @param array<string,mixed> $data
     * @param SecureHtmlRenderer|null $secureRenderer
     */
    public function __construct(
        Context $context,
        private readonly ServiceRegistry $serviceRegistry,
        private readonly Resolver $modelListResolver,
        private readonly BridgeRegistry $bridgeRegistry,
        private readonly SettingChecker $settingChecker,
        private readonly ConfigScopeResolver $scopeResolver,
        array $data = [],
        ?SecureHtmlRenderer $secureRenderer = null,
    ) {
        parent::__construct($context, $data, $secureRenderer);
    }

    /**
     * Whether the install is one where the reader of this page can act on a composer command.
     *
     * Nobody runs composer against a production install from the admin, so the instructions for
     * installing a bridge package, and the providers those instructions are about, are addressed
     * to a developer and shown only where a developer is the one looking.
     *
     * A mode Magento cannot report (a deployment config that predates the setting) is treated as
     * production, because the cost of being wrong is a page telling an administrator to run a
     * command they cannot run.
     *
     * Read off the block context rather than injected: every Template block already carries the
     * application state, and adding a constructor argument to a block breaks every install whose
     * generated interceptor was compiled against the old signature until it is recompiled.
     *
     * @return bool
     */
    public function isDeveloperMode(): bool
    {
        try {
            return $this->_appState->getMode() === State::MODE_DEVELOPER;
        } catch (LocalizedException) {
            return false;
        }
    }

    /**
     * Whether the stored value comes from deployment configuration instead of the database.
     *
     * `bin/magento app:config:dump` and `config:set --lock-env` write this path into
     * `app/etc/env.php`, and deployment configuration wins over the database. `Magento\Config\Model\Config`
     * then skips the field on save without saying so anywhere: the section still reports "You saved
     * the configuration" and stores nothing. Being registered `sensitive` does not prevent that, it
     * only decides which file the dump writes to.
     *
     * Asked of `SettingChecker` for the scope of the field being rendered, which is the exact check
     * the save path makes before skipping a field, and which also answers true at every scope when
     * the path is locked at default. It is deliberately not read off the element's `disabled` flag:
     * Magento's field renderer sets that too whenever "Use Default" or "Use Website" is ticked, so on
     * every inheriting website and store view the form claimed to be locked in env.php and hid its
     * controls. Inheritance is {@see isInherited()}, a separate state with its own behaviour.
     *
     * @return bool
     */
    public function isReadOnly(): bool
    {
        if (!$this->getData('element') instanceof DataObject) {
            return false;
        }
        $scope = $this->getEditedScope();

        return $this->settingChecker->isReadOnly(
            AiServiceSelector::CONFIG_PATH_AI_SERVICES,
            $scope->getType(),
            $scope->getCode(),
        );
    }

    /**
     * Whether this website or store view currently inherits the services from the scope above.
     *
     * That is the state in which Magento renders the "Use Default" / "Use Website" box ticked. The
     * form then starts out with its controls disabled, the way core renders an inherited field, and
     * Magento's own toggle script re-enables them when the box is unticked. Unlike a deployment lock
     * the field stays fully editable, so nothing is hidden.
     *
     * @return bool
     */
    public function isInherited(): bool
    {
        $element = $this->getData('element');
        if (!$element instanceof DataObject || !$element->getData('inherit')) {
            return false;
        }

        return (bool) ($element->getData('can_use_default_value') || $element->getData('can_use_website_value'));
    }

    /**
     * The scope parameters of the page, as a JSON object for the inline script.
     *
     * Test Connection and Refresh Models send these along, so they act on the rows of the scope on
     * screen rather than on default's.
     *
     * @return string
     */
    public function getScopeParamsJson(): string
    {
        return $this->encodeForScript($this->getEditedScope()->toRequestParams());
    }

    /**
     * The stored rows the script renders on load, each with the model list that row resolves to.
     *
     * Rows whose configuration is not an array are left out: they are not something the form can
     * render, and passing one on would only fail the script, which is the state the form most needs
     * to avoid. The model options are per row because the list belongs to the endpoint a row points
     * at, not to its provider; null for a provider no longer registered.
     *
     * A row of a provider that is no longer registered (its module removed or disabled) is still
     * handed over, flagged `registered => false`, so the form shows it as a read-only placeholder
     * instead of silently leaving it out. Left out, an administrator had no way to know the row was
     * there, and before the backend model kept such rows the next Save Config deleted it. `label`
     * is the administrator's own name for the row, which is the one thing besides the code that
     * says what the placeholder used to be.
     *
     * @return list<array{code:string,id:string,label:string,registered:bool,values:array<array-key,mixed>,modelOptions:list<array{value:string,label:string}>|null}>
     */
    public function getStoredRows(): array
    {
        $rows = [];
        $scope = $this->getEditedScope();
        foreach ($this->getArrayRows() as $rowId => $row) {
            $data = $row instanceof DataObject ? $row->toArray() : [];
            $code = array_key_first($data);
            $values = $code === null ? null : $data[$code];
            if (!is_string($code) || !is_array($values)) {
                continue;
            }
            $id = (string) $rowId;
            $label = $values[AiServiceInterface::CONFIGURATION_LABEL] ?? '';
            $rows[] = [
                'code' => $code,
                'id' => $id,
                'label' => is_string($label) ? $label : '',
                'registered' => $this->serviceRegistry->get($code) !== null,
                'values' => $values,
                'modelOptions' => $this->getRowModelOptions($code, $id, $scope),
            ];
        }

        return $rows;
    }

    /**
     * Encode a value as JSON that is safe to place in an inline script element.
     *
     * Every value that reaches the template's script goes through here; see
     * {@see SCRIPT_JSON_FLAGS} for what happened when one did not.
     *
     * @param array<array-key,mixed> $value
     * @return string
     * @throws \JsonException When the value cannot be encoded, rather than emitting `false` into
     *         the script, which would break it just the same
     */
    public function encodeForScript(array $value): string
    {
        return json_encode($value, self::SCRIPT_JSON_FLAGS);
    }

    /**
     * The providers offered as buttons on this install.
     *
     * In production the unusable ones are left out entirely rather than greyed out: their label
     * explains a package that the person reading cannot install, and the row it would add cannot
     * be tested from here. Developer mode keeps them, because there the label is actionable.
     *
     * Only the buttons are filtered. The field schema still carries every registered provider, so
     * a row saved for one of them keeps rendering and keeps its configuration.
     *
     * @return array<string,array{code:string,name:string,available:bool,supported:bool,package:string}>
     */
    public function getSelectableServices(): array
    {
        $buttons = $this->getServicesButtons();
        if ($this->isDeveloperMode()) {
            return $buttons;
        }

        return array_filter($buttons, static fn (array $button): bool => $button['available']);
    }

    /**
     * Whether any provider is being kept off this page because it cannot be used here.
     *
     * @return bool
     */
    public function hasHiddenServices(): bool
    {
        return count($this->getSelectableServices()) < count($this->getServicesButtons());
    }

    /**
     * Buttons rendered in the admin form, one per registered AI backend.
     *
     * @return array<string,array{code:string,name:string,available:bool,supported:bool,package:string}>
     */
    public function getServicesButtons(): array
    {
        return array_map(
            fn (AiServiceConfigurationInterface $service) => [
                'code' => $service->getCode(),
                'name' => $service->getName(),
                'available' => $this->bridgeRegistry->isAvailable($service->getCode()),
                'supported' => $this->bridgeRegistry->isSupported($service->getCode()),
                'package' => (string) $this->bridgeRegistry->getPackage($service->getCode()),
            ],
            $this->serviceRegistry->getAll(),
        );
    }

    /**
     * Composer packages needed to make currently unavailable providers usable, deduplicated.
     *
     * @return array<int, string>
     */
    public function getMissingBridgePackages(): array
    {
        $packages = [];
        foreach ($this->getServicesButtons() as $button) {
            if (!$button['available'] && $button['supported'] && $button['package'] !== '') {
                $packages[] = $button['package'];
            }
        }

        return array_values(array_unique($packages));
    }

    /**
     * Display names of providers for which no bridge has been released upstream.
     *
     * Distinct from providers whose package is merely missing: there is nothing to install for
     * these, so the form must not offer a composer command for them.
     *
     * @return array<int, string>
     */
    public function getUnsupportedServiceNames(): array
    {
        $names = [];
        foreach ($this->getServicesButtons() as $button) {
            if (!$button['supported']) {
                $names[] = $button['name'];
            }
        }

        return $names;
    }

    /**
     * Whether any registered provider cannot currently be used through the bundled client.
     *
     * @return bool
     */
    public function hasUnavailableServices(): bool
    {
        foreach ($this->getServicesButtons() as $button) {
            if (!$button['available']) {
                return true;
            }
        }

        return false;
    }

    /**
     * Field schema consumed by the admin form JavaScript.
     *
     * Each service entry carries its field descriptors (`fields`) and whether the backend supports
     * the live model-list refresh (`supportsModelRefresh`). The model options here are what a newly
     * added row starts with; a stored row gets its own list through {@see getStoredRows()}.
     *
     * @return string JSON object keyed by service code:
     *         {name: string, fields: array[], supportsModelRefresh: bool}
     */
    public function getServicesSchemaJson(): string
    {
        $schema = [];
        foreach ($this->serviceRegistry->getAll() as $code => $service) {
            $models = $this->modelListResolver->getModels($service);
            $schema[$code] = [
                // The rows are built in JavaScript, so the display name has to travel with the
                // schema. Without it a row shows its fields and never says which provider they
                // belong to, which is the one thing two rows of the same backend differ by.
                'name' => $service->getName(),
                'fields' => array_map(
                    fn (FieldDescriptorInterface $field) => [
                        'name'      => $field->getName(),
                        'label'     => $field->getLabel(),
                        'type'      => $field->getType(),
                        'options'   => $this->resolveFieldOptions($field, $models),
                        'default'   => $field->getDefault(),
                        'encrypted' => $field->isEncrypted(),
                        'placeholder' => $field->getPlaceholder(),
                        'advanced'  => $field->isAdvanced(),
                    ],
                    $service->getConfigurationFields(),
                ),
                'supportsModelRefresh' => $service instanceof ModelListProviderInterface,
            ];
        }
        return $this->encodeForScript($schema);
    }

    /**
     * Options for a field, substituting the resolved model list for the model field.
     *
     * Applies to the model field whatever its type. A select renders these as its options;
     * a free-text field renders them as datalist suggestions, so providers whose model list
     * cannot be known ahead of time (self-hosted Ollama and LM Studio, or OpenRouter's very
     * large catalogue) still benefit from a refresh instead of silently discarding it.
     *
     * @param FieldDescriptorInterface $field
     * @param array<string,string> $models Resolved model list (stored or curated) as value => label
     * @return array<int,array{value:string,label:string}>
     */
    private function resolveFieldOptions(FieldDescriptorInterface $field, array $models): array
    {
        return $field->getName() === self::FIELD_MODEL ? $this->toOptions($models) : $field->getOptions();
    }

    /**
     * Model options for one stored row, or null when its provider is not registered here.
     *
     * @param string $code
     * @param string $rowId
     * @param ConfigScope $scope
     * @return list<array{value:string,label:string}>|null
     */
    private function getRowModelOptions(string $code, string $rowId, ConfigScope $scope): ?array
    {
        $service = $this->serviceRegistry->get($code);
        if ($service === null) {
            return null;
        }

        return $this->toOptions($this->modelListResolver->getModelsForRow($service, $rowId, $scope));
    }

    /**
     * Turn a value => label model map into the option rows the script renders.
     *
     * @param array<string,string> $models
     * @return list<array{value:string,label:string}>
     */
    private function toOptions(array $models): array
    {
        return array_map(
            static fn (string|int $value, string $label): array => ['value' => (string) $value, 'label' => $label],
            array_keys($models),
            array_values($models),
        );
    }

    /**
     * The scope of the field being rendered, as the config form set it on the element.
     *
     * The config form puts `scope` (`default`, `websites` or `stores`) and `scope_id` on every
     * element it builds. A block that has not been handed an element yet answers default.
     *
     * @return ConfigScope
     */
    private function getEditedScope(): ConfigScope
    {
        $element = $this->getData('element');
        $type = $element instanceof DataObject ? $element->getData('scope') : null;

        return $this->scopeResolver->fromScopeId(
            is_string($type) ? $type : '',
            $element instanceof DataObject ? $element->getData('scope_id') : null,
        );
    }

    /**
     * @inheritdoc
     */
    protected function _prepareToRender(): void
    {
        $this->addColumn('service', [
            'label' => __('Service'),
            'class' => 'required-entry',
        ]);

        $this->_addAfter = false;
        $this->_addButtonLabel = (string) __('Add Service');
    }
}
