import { expect, test } from '@playwright/test';
import { AiConfigurationSection } from '../support/AiConfigurationSection';

/**
 * A provider that gives guidance is picked by tier: the cards are what an administrator compares,
 * and the model field behind them is still the one value the row saves.
 */
test.describe('Model tier cards', () => {
    test.beforeEach(async ({ page }) => {
        await new AiConfigurationSection(page).reset();
    });

    test('a new row starts on the recommended tier', async ({ page }) => {
        const section = new AiConfigurationSection(page);
        await section.open();
        await section.addProvider('anthropic');
        const cards = section.row(0).locator('.ai-tier-card');

        await expect(cards).toHaveCount(3);
        await expect(cards.filter({ has: page.locator('input:checked') })).toContainText('Balanced');
        await expect(cards.filter({ has: page.locator('input:checked') })).toContainText('Recommended');
        await expect(section.fieldByLabel(0, 'Model')).toHaveValue('claude-sonnet-5-5');
    });

    test('picking a card sets the model the row saves', async ({ page }) => {
        const section = new AiConfigurationSection(page);
        await section.open();
        await section.addProvider('anthropic');

        await section.row(0).locator('.ai-tier-card', { hasText: 'Fast' }).click();
        await section.save();

        await expect(section.fieldByLabel(0, 'Model')).toHaveValue('claude-haiku-5-5');
        await expect(section.row(0).locator('[data-model-summary]')).toHaveText('Uses Claude Haiku 5.5');
    });

    test('a specific model from the list lights the card of its tier', async ({ page }) => {
        const section = new AiConfigurationSection(page);
        await section.open();
        await section.addProvider('anthropic');
        const model = section.fieldByLabel(0, 'Model');

        await expect(model).toBeHidden();
        await section.openModelList(0);
        await model.selectOption('claude-opus-4-7');

        await expect(section.row(0).locator('.ai-tier-card.is-selected')).toContainText('Most capable');
    });

    test('a saved row lights the card of its model after a reload', async ({ page }) => {
        const section = new AiConfigurationSection(page);
        await section.open();
        await section.addProvider('anthropic');
        await section.row(0).locator('.ai-tier-card', { hasText: 'Fast' }).click();
        await section.save();

        await expect(section.row(0).locator('.ai-tier-card.is-selected')).toContainText('Fast');
        await expect(section.fieldByLabel(0, 'Model')).toBeHidden();
    });

    test('Refresh Models stays visible while the model list is folded away', async ({ page }) => {
        const section = new AiConfigurationSection(page);
        await section.open();
        await section.addProvider('anthropic');
        await section.save();

        await expect(section.fieldByLabel(0, 'Model')).toBeHidden();
        await expect(section.fieldRow(0, 'Model').locator('.ai-refresh-models')).toBeVisible();
    });

    test('a provider without guidance keeps the plain model field', async ({ page }) => {
        const section = new AiConfigurationSection(page);
        await section.open();
        await section.addProvider('openai');

        await expect(section.row(0).locator('.ai-tier-card')).toHaveCount(0);
        await expect(section.fieldByLabel(0, 'Model')).toBeVisible();
    });
});
