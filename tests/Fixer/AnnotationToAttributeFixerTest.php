<?php

declare(strict_types=1);

namespace PhpConverter\Tests\Fixer;

use PhpConverter\Fixer\AnnotationToAttributeFixer;
use PhpCsFixer\Test\AbstractFixerTestCase;

/**
 * @extends AbstractFixerTestCase<AnnotationToAttributeFixer>
 */
final class AnnotationToAttributeFixerTest extends AbstractFixerTestCase
{
    public function testConvertsOrmEntityAnnotation(): void
    {
        $this->doTest(
            <<<'PHP'
                <?php

                use Doctrine\ORM\Mapping as ORM;

                #[ORM\Entity]
                class User {}
                PHP,
            <<<'PHP'
                <?php

                use Doctrine\ORM\Mapping as ORM;

                /**
                 * @ORM\Entity
                 */
                class User {}
                PHP,
        );
    }

    protected function createFixer(): AnnotationToAttributeFixer
    {
        $fixer = new AnnotationToAttributeFixer();
        $fixer->configure([
            'annotation_map' => [
                'ORM\\Entity' => 'Doctrine\\ORM\\Mapping\\Entity',
            ],
            'custom_annotations' => [],
        ]);

        return $fixer;
    }
}
