<?php

declare(strict_types=1);

namespace PhpConverter\Analysis;

use PhpCsFixer\Tokenizer\Analyzer\TokensAnalyzer;
use PhpCsFixer\Tokenizer\CT;
use PhpCsFixer\Tokenizer\Tokens;

/**
 * Detects writes to instance properties within a class body.
 */
final class PropertyWriteAnalyzer
{
    /**
     * @return list<string> property names (without $) that receive writes outside allowed constructor assignments
     */
    public function findWrittenProperties(Tokens $tokens, int $classIndex): array
    {
        $tokensAnalyzer = new TokensAnalyzer($tokens);

        $propertyNames = [];
        foreach ($tokensAnalyzer->getClassyElements() as $element) {
            if ($element['classIndex'] !== $classIndex || $element['type'] !== 'property') {
                continue;
            }

            $propertyNames[] = substr($element['token']->getContent(), 1);
        }

        foreach ($this->getPromotedConstructorPropertyNames($tokens, $classIndex) as $promotedName) {
            if (!in_array($promotedName, $propertyNames, true)) {
                $propertyNames[] = $promotedName;
            }
        }

        if ($propertyNames === []) {
            return [];
        }

        $classOpen = $tokens->getNextTokenOfKind($classIndex, ['{']);
        if (!is_int($classOpen)) {
            return [];
        }

        $classClose = $tokens->findBlockEnd(Tokens::BLOCK_TYPE_CURLY_BRACE, $classOpen);
        $written = [];

        for ($index = $classOpen + 1; $index < $classClose; ++$index) {
            if (!$tokens[$index]->isGivenKind(\T_FUNCTION)) {
                continue;
            }

            $methodNameIndex = $tokens->getNextMeaningfulToken($index);
            if (!is_int($methodNameIndex)) {
                continue;
            }

            $isConstructor = $tokens[$methodNameIndex]->equals([\T_STRING, '__construct']);
            $bodyStart = $this->findMethodBodyStart($tokens, $index);
            if ($bodyStart === null) {
                continue;
            }

            $bodyEnd = $tokens->findBlockEnd(Tokens::BLOCK_TYPE_CURLY_BRACE, $bodyStart);

            foreach ($propertyNames as $propertyName) {
                if ($this->propertyIsWrittenInRange($tokens, $bodyStart, $bodyEnd, $propertyName, $isConstructor)) {
                    $written[$propertyName] = true;
                }
            }
        }

        return array_keys($written);
    }

    /**
     * Properties never written (excluding constructor-only simple assignments for promotion cases).
     *
     * @return list<string>
     */
    public function findNeverWrittenProperties(Tokens $tokens, int $classIndex): array
    {
        $written = $this->findWrittenProperties($tokens, $classIndex);
        $tokensAnalyzer = new TokensAnalyzer($tokens);
        $neverWritten = [];

        foreach ($tokensAnalyzer->getClassyElements() as $element) {
            if ($element['classIndex'] !== $classIndex || $element['type'] !== 'property') {
                continue;
            }

            $name = substr($element['token']->getContent(), 1);
            if (!in_array($name, $written, true)) {
                $neverWritten[] = $name;
            }
        }

        return $neverWritten;
    }

    private function findMethodBodyStart(Tokens $tokens, int $functionIndex): ?int
    {
        $parenOpen = $tokens->getNextTokenOfKind($functionIndex, ['(']);
        if (!is_int($parenOpen)) {
            return null;
        }

        $parenClose = $tokens->findBlockEnd(Tokens::BLOCK_TYPE_PARENTHESIS, $parenOpen);
        $brace = $tokens->getNextTokenOfKind($parenClose, ['{', ';']);
        if (!is_int($brace) || !$tokens[$brace]->equals('{')) {
            return null;
        }

        return $brace;
    }

    private function propertyIsWrittenInRange(
        Tokens $tokens,
        int $bodyStart,
        int $bodyEnd,
        string $propertyName,
        bool $isConstructor,
    ): bool {
        for ($index = $bodyStart + 1; $index < $bodyEnd; ++$index) {
            if ($tokens[$index]->isGivenKind(\T_UNSET)) {
                if ($this->isUnsetOnProperty($tokens, $index, $propertyName)) {
                    return true;
                }

                continue;
            }

            if (!$this->isThisPropertyAccess($tokens, $index, $propertyName)) {
                continue;
            }

            $afterProperty = $tokens->getNextMeaningfulToken($index + 1);
            if (!is_int($afterProperty)) {
                continue;
            }

            if ($tokens[$afterProperty]->equals('=')) {
                if (!$isConstructor) {
                    return true;
                }

                if (!$this->isSimpleConstructorAssignment($tokens, $index, $propertyName)) {
                    return true;
                }
            }

            if ($tokens[$afterProperty]->equals('&')) {
                return true;
            }

            if ($tokens[$afterProperty]->isGivenKind(CT::T_PLUS_EQUAL)
                || $tokens[$afterProperty]->isGivenKind(CT::T_MINUS_EQUAL)
                || $tokens[$afterProperty]->isGivenKind(CT::T_MUL_EQUAL)
                || $tokens[$afterProperty]->isGivenKind(CT::T_DIV_EQUAL)
            ) {
                return true;
            }
        }

        return false;
    }

    private function isThisPropertyAccess(Tokens $tokens, int $propertyNameIndex, string $propertyName): bool
    {
        if (!$tokens[$propertyNameIndex]->equals([\T_STRING, $propertyName])) {
            return false;
        }

        $objectOp = $tokens->getPrevMeaningfulToken($propertyNameIndex);
        if (!is_int($objectOp) || !$tokens[$objectOp]->isGivenKind(\T_OBJECT_OPERATOR)) {
            return false;
        }

        $thisVar = $tokens->getPrevMeaningfulToken($objectOp);
        if (!is_int($thisVar) || !$tokens[$thisVar]->equals([\T_VARIABLE, '$this'])) {
            return false;
        }

        $beforeThis = $tokens->getPrevMeaningfulToken($thisVar);
        if (is_int($beforeThis) && $tokens[$beforeThis]->equals('(')) {
            return false;
        }

        return true;
    }

    private function isSimpleConstructorAssignment(Tokens $tokens, int $propertyNameIndex, string $propertyName): bool
    {
        $equals = $tokens->getNextMeaningfulToken($propertyNameIndex);
        if (!is_int($equals) || !$tokens[$equals]->equals('=')) {
            return false;
        }

        $rhs = $tokens->getNextMeaningfulToken($equals);
        if (!is_int($rhs) || !$tokens[$rhs]->isGivenKind(\T_VARIABLE)) {
            return false;
        }

        $paramName = substr($tokens[$rhs]->getContent(), 1);

        return $paramName === $propertyName;
    }

    private function isUnsetOnProperty(Tokens $tokens, int $unsetIndex, string $propertyName): bool
    {
        $paren = $tokens->getNextTokenOfKind($unsetIndex, ['(']);
        if (!is_int($paren)) {
            return false;
        }

        $close = $tokens->findBlockEnd(Tokens::BLOCK_TYPE_PARENTHESIS, $paren);
        for ($i = $paren + 1; $i < $close; ++$i) {
            if ($this->isThisPropertyAccess($tokens, $i, $propertyName)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function getPromotedConstructorPropertyNames(Tokens $tokens, int $classIndex): array
    {
        $names = [];
        foreach ((new TokensAnalyzer($tokens))->getClassyElements() as $index => $element) {
            if ($element['classIndex'] !== $classIndex || $element['type'] !== 'method') {
                continue;
            }

            $nameIndex = $tokens->getNextMeaningfulToken($index);
            if (!is_int($nameIndex) || !$tokens[$nameIndex]->equals([\T_STRING, '__construct'])) {
                continue;
            }

            $parenOpen = $tokens->getNextTokenOfKind($index, ['(']);
            if (!is_int($parenOpen)) {
                continue;
            }

            $parenClose = $tokens->findBlockEnd(Tokens::BLOCK_TYPE_PARENTHESIS, $parenOpen);
            for ($i = $parenOpen + 1; $i < $parenClose; ++$i) {
                if (!$tokens[$i]->isGivenKind(\T_VARIABLE)) {
                    continue;
                }

                $prev = $tokens->getPrevMeaningfulToken($i);
                while (is_int($prev)) {
                    if ($tokens[$prev]->isGivenKind([
                        CT::T_CONSTRUCTOR_PROPERTY_PROMOTION_PUBLIC,
                        CT::T_CONSTRUCTOR_PROPERTY_PROMOTION_PROTECTED,
                        CT::T_CONSTRUCTOR_PROPERTY_PROMOTION_PRIVATE,
                    ])) {
                        $names[] = substr($tokens[$i]->getContent(), 1);

                        break;
                    }

                    if ($tokens[$prev]->equals('(') || $tokens[$prev]->equals(',')) {
                        break;
                    }

                    $prev = $tokens->getPrevMeaningfulToken($prev);
                }
            }
        }

        return $names;
    }
}
