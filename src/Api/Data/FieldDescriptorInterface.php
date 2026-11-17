<?php

declare(strict_types=1);

namespace MageOS\AiBase\Api\Data;

/**
 * Stable to call, not meant to be implemented: methods may be added in a minor release. To change
 * what it does, write a plugin on it instead of replacing it.
 *
 * @api
 */
interface FieldDescriptorInterface
{
    public const TYPE_TEXT     = 'text';
    public const TYPE_PASSWORD = 'password';
    public const TYPE_SELECT   = 'select';

    /**
     * Field name used as the input name suffix.
     *
     * @return string
     */
    public function getName(): string;

    /**
     * Human-readable field label.
     *
     * @return string
     */
    public function getLabel(): string;

    /**
     * Input type; one of the TYPE_* constants.
     *
     * @return string
     */
    public function getType(): string;

    /**
     * Options for select fields.
     *
     * @return array<int, array{value: string, label: string}>
     */
    public function getOptions(): array;

    /**
     * Default value pre-filled in the input, if any.
     *
     * @return string|null
     */
    public function getDefault(): ?string;

    /**
     * Whether the field holds a credential that must be encrypted at rest and masked in the admin form.
     *
     * @return bool
     */
    public function isEncrypted(): bool;

    /**
     * Whether the field names the host the row's credentials are sent to.
     *
     * A stored credential is only ever restored from its masked placeholder while the row keeps
     * pointing at the host it was saved for. Without this flag an administrator could point the
     * row at a server they control, leave the key masked, press Test Connection and read the key
     * off their own server. Set it on every field whose value decides where a request goes (a base
     * URL, an endpoint, a host), whatever the field is called.
     *
     * @return bool
     */
    public function isEndpoint(): bool;

    /**
     * Hint shown in an empty input, if any; never stored.
     *
     * Unlike getDefault(), which fills the input and so ends up saved with the row, a placeholder
     * only shows what an empty field falls back to. A row then keeps following that fallback when it
     * changes, instead of carrying a copy of it from the day it was saved.
     *
     * @return string|null
     */
    public function getPlaceholder(): ?string;

    /**
     * Whether the field is one most administrators never need, shown collapsed under "Advanced".
     *
     * @return bool
     */
    public function isAdvanced(): bool;
}
