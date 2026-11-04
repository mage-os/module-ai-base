<?php

declare(strict_types=1);

namespace MageOS\AiBase\Model;

use MageOS\AiBase\Api\Data\FieldDescriptorInterface;

class FieldDescriptor implements FieldDescriptorInterface
{
    /**
     * @param string $name
     * @param string $label
     * @param string $type
     * @param array<int,array{value:string,label:string}> $options
     * @param string|null $default
     * @param bool $encrypted
     * @param bool $endpoint Whether the field names the host credentials are sent to; defaults to
     *        false so a `create([...])` call written before the flag existed keeps working
     * @param string|null $placeholder
     * @param bool $advanced Whether the form collapses the field under "Advanced"
     */
    public function __construct(
        private readonly string $name,
        private readonly string $label,
        private readonly string $type,
        private readonly array $options = [],
        private readonly ?string $default = null,
        private readonly bool $encrypted = false,
        private readonly bool $endpoint = false,
        private readonly ?string $placeholder = null,
        private readonly bool $advanced = false,
    ) {
    }

    /**
     * @inheritdoc
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * @inheritdoc
     */
    public function getLabel(): string
    {
        return $this->label;
    }

    /**
     * @inheritdoc
     */
    public function getType(): string
    {
        return $this->type;
    }

    /**
     * @inheritdoc
     */
    public function getOptions(): array
    {
        return $this->options;
    }

    /**
     * @inheritdoc
     */
    public function getDefault(): ?string
    {
        return $this->default;
    }

    /**
     * @inheritdoc
     */
    public function isEncrypted(): bool
    {
        return $this->encrypted;
    }

    /**
     * @inheritdoc
     */
    public function isEndpoint(): bool
    {
        return $this->endpoint;
    }

    /**
     * @inheritdoc
     */
    public function getPlaceholder(): ?string
    {
        return $this->placeholder;
    }

    /**
     * @inheritdoc
     */
    public function isAdvanced(): bool
    {
        return $this->advanced;
    }
}
