<?php

declare(strict_types=1);

namespace MageOS\AiBase\Controller\Adminhtml\Service;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use MageOS\AiBase\Api\AiServiceSelectorInterface;
use MageOS\AiBase\Api\Data\AiServiceInterface;
use MageOS\AiBase\Api\ModelListProviderInterface;
use MageOS\AiBase\Model\Config\ConfigScope;
use MageOS\AiBase\Model\Config\ConfigScopeResolver;
use MageOS\AiBase\Model\FailureReporter;
use MageOS\AiBase\Model\ModelList\ModelOptions;
use MageOS\AiBase\Model\ModelList\Storage;
use MageOS\AiBase\Model\ServiceRegistry;
use MageOS\AiBase\Model\ServiceScope;

/**
 * Live-fetches the model list of a configured AI service and persists it for the admin form.
 *
 * Acts on the scope the config page is showing: the form sends that page's `website` or `store`
 * parameter along, the row is read at that scope, and the list is stored for that row at that
 * scope. Without it, refreshing a row that only exists on a website reported it missing, and a row
 * a website overrides was fetched with default's credentials.
 *
 * Extends Backend\App\Action so admin authentication, form-key validation and
 * ACL enforcement (via ADMIN_RESOURCE) apply through the standard plugins.
 */
class RefreshModels extends Action implements HttpPostActionInterface
{
    /**
     * Authorization resource, reuses the configuration ACL entry.
     */
    public const ADMIN_RESOURCE = 'MageOS_AiBase::configuration';

    /**
     * @param Context $context
     * @param JsonFactory $jsonFactory
     * @param AiServiceSelectorInterface $serviceSelector
     * @param Storage $modelListStorage
     * @param ServiceRegistry $serviceRegistry Registered backends, the same set the admin form gets
     * @param FailureReporter $failureReporter Logs a failure in full and decides what the page shows
     * @param ConfigScopeResolver $scopeResolver Reads the scope the config page sent along
     * @param ServiceScope $serviceScope Makes the selector read the rows of that scope
     * @param ModelOptions $modelOptions Builds the refreshed options with the provider's guidance, so a
     *        refresh keeps the labels the page rendered
     */
    public function __construct(
        Context $context,
        private readonly JsonFactory $jsonFactory,
        private readonly AiServiceSelectorInterface $serviceSelector,
        private readonly Storage $modelListStorage,
        private readonly ServiceRegistry $serviceRegistry,
        private readonly FailureReporter $failureReporter,
        private readonly ConfigScopeResolver $scopeResolver,
        private readonly ServiceScope $serviceScope,
        private readonly ModelOptions $modelOptions,
    ) {
        parent::__construct($context);
    }

    /**
     * Refresh the model list for the requested row and report the outcome as JSON.
     *
     * The provider whose fetchModels() runs is the one the resolved row is stored as, never the
     * posted `service_code` on its own. The row's credentials go to whatever host that provider
     * talks to, so taking the provider from the post and the credentials from `service_id` would
     * let a crafted request send one row's key to another provider's host. A posted code that
     * disagrees with the row is refused before anything is fetched.
     *
     * @return Json
     */
    public function execute(): Json
    {
        $result = $this->jsonFactory->create();
        $serviceId = $this->getRequestedParam('service_id');
        $postedCode = $this->getRequestedParam('service_code');
        if ($serviceId === '' && $postedCode === '') {
            return $result->setData([
                'success' => false,
                'error' => (string) __('service_code is required'),
            ]);
        }

        try {
            $scope = $this->scopeResolver->fromRequest($this->getRequest());
            $configured = $this->resolveRow($serviceId, $postedCode, $scope);
            if ($configured === null) {
                return $result->setData([
                    'success' => false,
                    'error' => $this->describeMissingRow($serviceId, $postedCode),
                ]);
            }

            $serviceCode = $configured->getCode();
            if ($postedCode !== '' && $postedCode !== $serviceCode) {
                return $result->setData([
                    'success' => false,
                    'error' => (string) __(
                        'This row is stored as a "%1" service, not "%2", so its models were not '
                        . 'refreshed. Reload the page and try again.',
                        $serviceCode,
                        $postedCode
                    ),
                ]);
            }

            $definition = $this->serviceRegistry->get($serviceCode);
            if (!$definition instanceof ModelListProviderInterface) {
                return $result->setData([
                    'success' => false,
                    'error' => (string) __('Model list refresh is not supported for this service.'),
                ]);
            }

            $models = $definition->fetchModels($configured->getConfiguration());
            $this->modelListStorage->saveForRow($configured->getId(), $models, $scope);

            return $result->setData([
                'success' => true,
                'count' => count($models),
                'models' => $models,
                'options' => $this->modelOptions->build($definition, $models),
            ]);
        } catch (\Throwable $e) {
            return $result->setData([
                'success' => false,
                'error' => $this->failureReporter->report(
                    __('Model list refresh failed'),
                    $e,
                    ['service_id' => $serviceId, 'service_code' => $postedCode],
                ),
            ]);
        }
    }

    /**
     * The configured row whose credentials the list is fetched with, read at the edited scope.
     *
     * Both the key that fetches a list and the endpoint it comes from belong to a row. An
     * administrator with two rows of the same provider, which is the setup row ids exist for, would
     * otherwise refresh from the first row's account no matter which button they pressed, and read
     * the resulting error against the key in front of them. The code is only used to find a row
     * when no id was sent.
     *
     * @param string $serviceId
     * @param string $serviceCode
     * @param ConfigScope $scope
     * @return AiServiceInterface|null
     */
    private function resolveRow(string $serviceId, string $serviceCode, ConfigScope $scope): ?AiServiceInterface
    {
        return $this->serviceScope->run(
            $scope,
            fn (): ?AiServiceInterface => $serviceId !== ''
                ? $this->serviceSelector->getById($serviceId)
                : ($this->serviceSelector->getByCode($serviceCode)[0] ?? null),
        );
    }

    /**
     * The message for a request whose row could not be found, naming what was asked for.
     *
     * @param string $serviceId
     * @param string $serviceCode
     * @return string
     */
    private function describeMissingRow(string $serviceId, string $serviceCode): string
    {
        return $serviceId !== ''
            ? (string) __('No AI service configured with id "%1".', $serviceId)
            : (string) __('No AI service configured for code "%1".', $serviceCode);
    }

    /**
     * A request parameter, ignoring one that did not arrive as a string.
     *
     * Query parameters are whatever the caller put in the URL: `?service_code[]=x` arrives as an
     * array, and casting that hands the string `Array` to the registry as if it were a code.
     *
     * @param string $name
     * @return string
     */
    private function getRequestedParam(string $name): string
    {
        $value = $this->getRequest()->getParam($name);

        return is_string($value) ? $value : '';
    }
}
