<?php

namespace Tests\Unit;

use App\Services\Documents\EvidencePageLocator;
use PHPUnit\Framework\TestCase;

class EvidencePageLocatorTest extends TestCase
{
    public function test_exact_unique_excerpt_has_a_page(): void
    {
        self::assertSame(2, (new EvidencePageLocator)->locate("First page\fSecond page evidence", 2, 'page evidence'));
    }

    public function test_ambiguous_or_unstructured_text_has_no_claimed_page(): void
    {
        $locator = new EvidencePageLocator;
        self::assertNull($locator->locate("Evidence\fEvidence", 2, 'Evidence'));
        self::assertNull($locator->locate('Evidence', 2, 'Evidence'));
        self::assertNull($locator->locate("One\fTwo", 3, 'Two'));
        self::assertNull($locator->locate("One\fTwo", 2, 'Invented'));
    }

    public function test_ocr_style_spacing_case_and_punctuation_still_resolve_to_one_page(): void
    {
        $text = "Cover page\fRef:  TG-2026-0118  shall be\n paid by 30 June 2026.\fAnnex";
        self::assertSame(2, (new EvidencePageLocator)->locate($text, 3, 'ref TG-2026-0118 SHALL be paid by 30 june 2026'));
    }

    public function test_folded_match_on_several_pages_or_too_short_claims_no_page(): void
    {
        $locator = new EvidencePageLocator;
        self::assertNull($locator->locate("Pay  now, please\fPay now please", 2, 'PAY NOW PLEASE'));
        self::assertNull($locator->locate("A-B\fC", 2, 'a b'));
    }

    public function test_a_docx_without_page_breaks_claims_no_page(): void
    {
        self::assertNull((new EvidencePageLocator)->locate('Clause 4. Payment is due in 30 days.', 0, 'Payment is due in 30 days'));
    }
}
