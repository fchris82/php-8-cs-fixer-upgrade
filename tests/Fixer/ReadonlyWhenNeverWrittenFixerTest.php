<?php

declare(strict_types=1);

namespace PhpConverter\Tests\Fixer;

use PhpConverter\Fixer\ReadonlyWhenNeverWrittenFixer;
use PhpCsFixer\Test\AbstractFixerTestCase;

/**
 * @extends AbstractFixerTestCase<ReadonlyWhenNeverWrittenFixer>
 */
final class ReadonlyWhenNeverWrittenFixerTest extends AbstractFixerTestCase
{
    public function testAddsReadonlyWhenPropertyIsNeverWritten(): void
    {
        $this->doTest(
            <<<'PHP'
                <?php
                class Foo {
                    private readonly string $id;

                    public function __construct(string $id) {
                        $this->id = $id;
                    }

                    public function getId(): string {
                        return $this->id;
                    }
                }
                PHP,
            <<<'PHP'
                <?php
                class Foo {
                    private string $id;

                    public function __construct(string $id) {
                        $this->id = $id;
                    }

                    public function getId(): string {
                        return $this->id;
                    }
                }
                PHP,
        );
    }

    public function testSkipsPropertyWrittenInSetter(): void
    {
        $this->doTest(
            <<<'PHP'
                <?php
                class Foo {
                    private string $value;

                    public function setValue(string $value): void {
                        $this->value = $value;
                    }
                }
                PHP,
        );
    }

    protected function createFixer(): ReadonlyWhenNeverWrittenFixer
    {
        return new ReadonlyWhenNeverWrittenFixer();
    }
}
