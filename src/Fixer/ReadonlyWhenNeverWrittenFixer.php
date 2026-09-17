<?php

declare(strict_types=1);

namespace PhpConverter\Fixer;

use PhpConverter\Analysis\PropertyWriteAnalyzer;
use PhpCsFixer\FixerDefinition\CodeSample;
use PhpCsFixer\FixerDefinition\FixerDefinitionInterface;
use PhpCsFixer\Tokenizer\Analyzer\TokensAnalyzer;
use PhpCsFixer\Tokenizer\CT;
use PhpCsFixer\Tokenizer\Token;
use PhpCsFixer\Tokenizer\Tokens;

final class ReadonlyWhenNeverWrittenFixer extends AbstractPhpConverterFixer
{
    private PropertyWriteAnalyzer $writeAnalyzer;

    public function __construct()
    {
        parent::__construct();
        $this->writeAnalyzer = new PropertyWriteAnalyzer();
    }

    public static function name(): string
    {
        return 'PhpConverter/readonly_when_never_written';
    }

    protected function ruleName(): string
    {
        return 'readonly_when_never_written';
    }

    public function getDefinition(): FixerDefinitionInterface
    {
        return $this->buildDefinition(
            'Add `readonly` to typed properties that are never written after construction.',
            [
                new CodeSample(
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
                ),
            ],
        );
    }

    public function isCandidate(Tokens $tokens): bool
    {
        return $tokens->isTokenKindFound(\T_CLASS) && $tokens->isTokenKindFound(\T_VARIABLE);
    }

    /**
     * After PromotedConstructorPropertyFixer (priority 56).
     */
    public function getPriority(): int
    {
        return 55;
    }

    protected function fixFile(\SplFileInfo $file, Tokens $tokens): void
    {
        for ($index = $tokens->count() - 1; $index > 0; --$index) {
            if (!$tokens[$index]->isGivenKind(\T_CLASS)) {
                continue;
            }

            if ($this->isAnonymousClass($tokens, $index)) {
                continue;
            }

            $neverWritten = $this->writeAnalyzer->findNeverWrittenProperties($tokens, $index);
            if ($neverWritten === []) {
                continue;
            }

            $tokensAnalyzer = new TokensAnalyzer($tokens);
            foreach ($tokensAnalyzer->getClassyElements() as $elementIndex => $element) {
                if ($element['classIndex'] !== $index || $element['type'] !== 'property') {
                    continue;
                }

                $propertyName = substr($element['token']->getContent(), 1);
                if (!in_array($propertyName, $neverWritten, true)) {
                    continue;
                }

                $this->addReadonlyModifier($tokens, $elementIndex);
            }

            $this->addReadonlyToPromotedParameters($tokens, $index, $neverWritten);
        }
    }

    private function isAnonymousClass(Tokens $tokens, int $classIndex): bool
    {
        $prev = $tokens->getPrevMeaningfulToken($classIndex);

        return is_int($prev) && $tokens[$prev]->equals([\T_NEW, 'new']);
    }

    private function addReadonlyModifier(Tokens $tokens, int $propertyIndex): void
    {
        if ($this->hasReadonly($tokens, $propertyIndex)) {
            return;
        }

        if (!$this->propertyHasType($tokens, $propertyIndex)) {
            return;
        }

        if ($this->isStaticProperty($tokens, $propertyIndex)) {
            return;
        }

        $visibilityIndex = $this->findVisibilityIndex($tokens, $propertyIndex);
        if ($visibilityIndex === null) {
            return;
        }

        $tokens->insertAt($visibilityIndex + 1, [
            new Token([\T_WHITESPACE, ' ']),
            new Token([CT::T_READONLY, 'readonly']),
        ]);
    }

    private function addReadonlyToPromotedParameters(Tokens $tokens, int $classIndex, array $neverWritten): void
    {
        $constructorIndex = $this->findConstructorIndex($tokens, $classIndex);
        if ($constructorIndex === null) {
            return;
        }

        $parenOpen = $tokens->getNextTokenOfKind($constructorIndex, ['(']);
        if (!is_int($parenOpen)) {
            return;
        }

        $parenClose = $tokens->findBlockEnd(Tokens::BLOCK_TYPE_PARENTHESIS, $parenOpen);

        for ($i = $parenOpen + 1; $i < $parenClose; ++$i) {
            if (!$tokens[$i]->isGivenKind(\T_VARIABLE)) {
                continue;
            }

            if (!$this->isPromotedParameter($tokens, $i)) {
                continue;
            }

            $propertyName = substr($tokens[$i]->getContent(), 1);
            if (!in_array($propertyName, $neverWritten, true)) {
                continue;
            }

            if ($this->hasReadonly($tokens, $i)) {
                continue;
            }

            $visibilityIndex = $this->findPromotionVisibilityIndex($tokens, $i);
            if ($visibilityIndex === null) {
                continue;
            }

            $tokens->insertAt($visibilityIndex + 1, [
                new Token([\T_WHITESPACE, ' ']),
                new Token([CT::T_READONLY, 'readonly']),
            ]);
        }
    }

    private function findConstructorIndex(Tokens $tokens, int $classIndex): ?int
    {
        $tokensAnalyzer = new TokensAnalyzer($tokens);
        foreach ($tokensAnalyzer->getClassyElements() as $index => $element) {
            if ($element['classIndex'] !== $classIndex || $element['type'] !== 'method') {
                continue;
            }

            $nameIndex = $tokens->getNextMeaningfulToken($index);
            if (is_int($nameIndex) && $tokens[$nameIndex]->equals([\T_STRING, '__construct'])) {
                return $index;
            }
        }

        return null;
    }

    private function isPromotedParameter(Tokens $tokens, int $variableIndex): bool
    {
        $prev = $tokens->getPrevMeaningfulToken($variableIndex);
        while (is_int($prev)) {
            if ($tokens[$prev]->isGivenKind([
                CT::T_CONSTRUCTOR_PROPERTY_PROMOTION_PUBLIC,
                CT::T_CONSTRUCTOR_PROPERTY_PROMOTION_PROTECTED,
                CT::T_CONSTRUCTOR_PROPERTY_PROMOTION_PRIVATE,
            ])) {
                return true;
            }

            if ($tokens[$prev]->equals('(') || $tokens[$prev]->equals(',')) {
                return false;
            }

            $prev = $tokens->getPrevMeaningfulToken($prev);
        }

        return false;
    }

    private function findPromotionVisibilityIndex(Tokens $tokens, int $variableIndex): ?int
    {
        $prev = $tokens->getPrevMeaningfulToken($variableIndex);
        while (is_int($prev)) {
            if ($tokens[$prev]->isGivenKind([
                CT::T_CONSTRUCTOR_PROPERTY_PROMOTION_PUBLIC,
                CT::T_CONSTRUCTOR_PROPERTY_PROMOTION_PROTECTED,
                CT::T_CONSTRUCTOR_PROPERTY_PROMOTION_PRIVATE,
            ])) {
                return $prev;
            }

            if ($tokens[$prev]->equals('(') || $tokens[$prev]->equals(',')) {
                return null;
            }

            $prev = $tokens->getPrevMeaningfulToken($prev);
        }

        return null;
    }

    private function hasReadonly(Tokens $tokens, int $index): bool
    {
        $scan = $index;
        for ($n = 0; $n < 8; ++$n) {
            $prev = $tokens->getPrevMeaningfulToken($scan);
            if (!is_int($prev)) {
                break;
            }

            if ($tokens[$prev]->isGivenKind(CT::T_READONLY)) {
                return true;
            }

            if ($tokens[$prev]->isGivenKind([\T_PRIVATE, \T_PROTECTED, \T_PUBLIC, \T_VAR])
                || $tokens[$prev]->isGivenKind([
                    CT::T_CONSTRUCTOR_PROPERTY_PROMOTION_PUBLIC,
                    CT::T_CONSTRUCTOR_PROPERTY_PROMOTION_PROTECTED,
                    CT::T_CONSTRUCTOR_PROPERTY_PROMOTION_PRIVATE,
                ])
            ) {
                $scan = $prev;

                continue;
            }

            break;
        }

        return false;
    }

    private function propertyHasType(Tokens $tokens, int $propertyIndex): bool
    {
        $prev = $tokens->getPrevMeaningfulToken($propertyIndex);

        return is_int($prev) && ($tokens[$prev]->isGivenKind(\T_STRING)
            || $tokens[$prev]->isGivenKind(\T_ARRAY)
            || $tokens[$prev]->isGivenKind(\T_CALLABLE)
            || $tokens[$prev]->isGivenKind(CT::T_NULLABLE_TYPE)
            || $tokens[$prev]->isGivenKind(CT::T_TYPE_ALTERNATION)
            || $tokens[$prev]->isGivenKind(CT::T_TYPE_INTERSECTION)
            || $tokens[$prev]->equals('?'));
    }

    private function isStaticProperty(Tokens $tokens, int $propertyIndex): bool
    {
        $prev = $tokens->getPrevMeaningfulToken($propertyIndex);
        while (is_int($prev)) {
            if ($tokens[$prev]->isGivenKind(\T_STATIC)) {
                return true;
            }

            if ($tokens[$prev]->isGivenKind([\T_PRIVATE, \T_PROTECTED, \T_PUBLIC, \T_VAR])) {
                return false;
            }

            $prev = $tokens->getPrevMeaningfulToken($prev);
        }

        return false;
    }

    private function findVisibilityIndex(Tokens $tokens, int $propertyIndex): ?int
    {
        $prev = $tokens->getPrevMeaningfulToken($propertyIndex);
        while (is_int($prev)) {
            if ($tokens[$prev]->isGivenKind([\T_PRIVATE, \T_PROTECTED, \T_PUBLIC, \T_VAR])) {
                return $prev;
            }

            if ($tokens[$prev]->equals('{') || $tokens[$prev]->equals(';')) {
                return null;
            }

            $prev = $tokens->getPrevMeaningfulToken($prev);
        }

        return null;
    }
}
