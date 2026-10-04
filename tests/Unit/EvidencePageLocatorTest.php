<?php

namespace Tests\Unit;

use App\Services\Documents\EvidencePageLocator;
use PHPUnit\Framework\TestCase;

class EvidencePageLocatorTest extends TestCase
{
    public function test_exact_unique_excerpt_has_a_page(): void
    {
        self::assertSame(2, (new EvidencePageLocator())->locate("First page\fSecond page evidence", 2, 'page evidence'));
    }

    public function test_ambiguous_or_unstructured_text_has_no_claimed_page(): void
    {
        $locator = new EvidencePageLocator();
        self::assertNull($locator->locate("Evidence\fEvidence", 2, 'Evidence'));
        self::assertNull($locator->locate('Evidence', 2, 'Evidence'));
        self::assertNull($locator->locate("One\fTwo", 3, 'Two'));
        self::assertNull($locator->locate("One\fTwo", 2, 'Invented'));
    }
}
