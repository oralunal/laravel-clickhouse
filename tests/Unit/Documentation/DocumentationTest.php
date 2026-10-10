<?php

namespace Tests\Unit\Documentation;

use PHPUnit\Framework\TestCase;

/**
 * The documentation covers the public API: docs/reference/api.md lists every public class, constant, method and
 * function as the code declares them, and a guide page names every public method that is not internal.
 */
class DocumentationTest extends TestCase
{
    public function test_the_api_reference_agrees_with_the_code(): void
    {
        $this->assertFileExists(ApiReference::PAGE);

        $this->assertSame(
            ApiReference::generate(),
            file_get_contents(ApiReference::PAGE),
            'docs/reference/api.md does not agree with the code. Run `composer docs:api` and commit the page.'
        );
    }

    public function test_a_guide_page_names_every_public_method(): void
    {
        $missing = ApiReference::methodsWithoutGuide(ApiReference::guides());

        $this->assertSame(
            [],
            $missing,
            "No guide page in docs/ names these public methods. Describe them in the guide page of their class:\n"
            . implode("\n", $missing)
        );
    }

    /**
     * Without guide pages, the check reports the public methods, but not the methods of the internal classes and
     * not the methods that implement Laravel's or PHP's contracts.
     */
    public function test_the_check_reports_a_method_that_no_guide_names(): void
    {
        $missing = ApiReference::methodsWithoutGuide('');

        $this->assertContains('ClickhouseBuilder\\Query\\BaseBuilder::where', $missing);
        $this->assertContains('Builder::followConnectionOptions', $missing);
        $this->assertContains('Concerns\\HasAttributes::fillJsonAttribute', $missing);
        $this->assertNotContains('Connection::prepareBindings', $missing);
        $this->assertNotContains('Enum\\Enum::jsonSerialize', $missing);
        $this->assertNotContains('QueryGrammar::compileSelect', $missing);

        $this->assertNotContains(
            'ClickhouseBuilder\\Query\\BaseBuilder::where',
            ApiReference::methodsWithoutGuide('Use `where()` for a condition.')
        );
    }
}
