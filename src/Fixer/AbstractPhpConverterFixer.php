<?php

declare(strict_types=1);

namespace PhpConverter\Fixer;

use PhpCsFixer\AbstractFixer;
use PhpCsFixer\FixerDefinition\FixerDefinition;
use PhpCsFixer\FixerDefinition\FixerDefinitionInterface;
use PhpCsFixer\Tokenizer\Tokens;

abstract class AbstractPhpConverterFixer extends AbstractFixer
{
    abstract protected function ruleName(): string;

    public function __construct()
    {
        parent::__construct();
    }

    public function getName(): string
    {
        return 'PhpConverter/'.$this->ruleName();
    }

    public function isRisky(): bool
    {
        return true;
    }

    public function supports(\SplFileInfo $file): bool
    {
        return true;
    }

    protected function applyFix(\SplFileInfo $file, Tokens $tokens): void
    {
        $this->fixFile($file, $tokens);
    }

    abstract protected function fixFile(\SplFileInfo $file, Tokens $tokens): void;

    protected function buildDefinition(string $summary, array $samples = []): FixerDefinitionInterface
    {
        return new FixerDefinition($summary, $samples);
    }
}
