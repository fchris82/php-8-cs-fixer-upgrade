<?php

declare(strict_types=1);

namespace PhpConverter\Tests\Fixer;

use PhpConverter\Fixer\AnnotationClassToAttributeClassFixer;
use PhpCsFixer\Test\AbstractFixerTestCase;

/**
 * @extends AbstractFixerTestCase<AnnotationClassToAttributeClassFixer>
 */
final class AnnotationClassToAttributeClassFixerTest extends AbstractFixerTestCase
{
    public function testConvertsAnnotationClassToAttribute(): void
    {
        $this->doTest(
            <<<'PHP'
                <?php

                #[\Attribute(\Attribute::TARGET_CLASS)]
                final class MyAnnotation
                {
                    public function __construct(public string $value) {}
                }
                PHP,
            <<<'PHP'
                <?php

                /**
                 * @Annotation
                 * @Target({"CLASS"})
                 */
                final class MyAnnotation
                {
                    /** @var string */
                    public $value;
                }
                PHP,
        );
    }

    protected function createFixer(): AnnotationClassToAttributeClassFixer
    {
        return new AnnotationClassToAttributeClassFixer();
    }
}
