<?php
/**
 * DISCLAIMER
 *
 * Do not edit or add to this file if you wish to upgrade Gally to newer versions in the future.
 *
 * @package   Gally
 * @author    Gally Team <elasticsuite@smile.fr>
 * @copyright 2022-present Smile
 * @license   Open Software License v. 3.0 (OSL-3.0)
 */

declare(strict_types=1);

namespace Gally\ShopwarePlugin\Tests\Indexer\Provider;

use Gally\ShopwarePlugin\Config\ConfigManager;
use Gally\ShopwarePlugin\Indexer\Provider\CatalogProvider;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\System\Currency\CurrencyEntity;
use Shopware\Core\System\Language\LanguageEntity;
use Shopware\Core\System\Locale\LocaleEntity;
use Shopware\Core\System\SalesChannel\SalesChannelEntity;

class CatalogProviderTest extends TestCase
{
    private function buildSalesChannel(): SalesChannelEntity
    {
        $currency = new CurrencyEntity();
        $currency->setIsoCode('EUR');

        $salesChannel = new SalesChannelEntity();
        $salesChannel->setId('sc-1');
        $salesChannel->setName('Storefront');
        $salesChannel->setCurrency($currency);

        return $salesChannel;
    }

    private function buildLanguage(string $localeCode): LanguageEntity
    {
        $locale = new LocaleEntity();
        $locale->setCode($localeCode);

        $language = new LanguageEntity();
        $language->setId('lang-1');
        $language->setName('English');
        $language->setLocale($locale);

        return $language;
    }

    private function getProvider(): CatalogProvider
    {
        return new CatalogProvider(
            $this->createMock(ConfigManager::class),
            $this->createMock(EntityRepository::class),
        );
    }

    public function testValidLocaleIsAccepted(): void
    {
        $catalog = $this->getProvider()->buildLocalizedCatalog($this->buildSalesChannel(), $this->buildLanguage('en-GB'));

        $this->assertSame('en_GB', $catalog->getLocale());
    }

    public function testIncompleteLocaleIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->getProvider()->buildLocalizedCatalog($this->buildSalesChannel(), $this->buildLanguage('en'));
    }
}
