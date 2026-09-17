<?php

declare(strict_types=1);

namespace PhpConverter\Fixer;

use PhpCsFixer\FixerDefinition\CodeSample;
use PhpCsFixer\FixerDefinition\FixerDefinitionInterface;
use PhpCsFixer\Preg;
use PhpCsFixer\Tokenizer\Analyzer\TokensAnalyzer;
use PhpCsFixer\Tokenizer\Token;
use PhpCsFixer\Tokenizer\Tokens;

final class AnnotationClassToAttributeClassFixer extends AbstractPhpConverterFixer
{
    public static function name(): string
    {
        return 'PhpConverter/annotation_class_to_attribute_class';
    }

    protected function ruleName(): string
    {
        return 'annotation_class_to_attribute_class';
    }

    public function getDefinition(): FixerDefinitionInterface
    {
        return $this->buildDefinition(
            'Convert Doctrine-style annotation classes to PHP 8 attribute classes.',
            [
                new CodeSample(
                    <<<'PHP'
                    <?php

                    /**
                     * @Annotation
                     * @Target({"PROPERTY"})
                     */
                    final class MyAnnotation
                    {
                        /** @var string */
                        public $value;
                    }
                    PHP,
                ),
            ],
        );
    }

    public function isCandidate(Tokens $tokens): bool
    {
        return $tokens->isTokenKindFound(\T_CLASS) && $tokens->isTokenKindFound(\T_DOC_COMMENT);
    }

    public function getPriority(): int
    {
        return 60;
    }

    protected function fixFile(\SplFileInfo $file, Tokens $tokens): void
    {
        for ($index = $tokens->count() - 1; $index > 0; --$index) {
            if (!$tokens[$index]->isGivenKind(\T_CLASS)) {
                continue;
            }

            $docIndex = $tokens->getPrevTokenOfKind($index, [[\T_DOC_COMMENT]]);
            if (!is_int($docIndex)) {
                continue;
            }

            $doc = $tokens[$docIndex]->getContent();
            if (!Preg::match('/@Annotation\b/', $doc)) {
                continue;
            }

            if ($this->classHasNonPropertyMembers($tokens, $index)) {
                continue;
            }

            $targets = $this->parseTargets($doc);
            $properties = $this->collectPublicProperties($tokens, $index);

            $this->insertClassAttribute($tokens, $index, $targets);
            $this->replaceClassDocblock($tokens, $docIndex);
            $this->buildConstructorFromProperties($tokens, $index, $properties);
        }
    }

    private function classHasNonPropertyMembers(Tokens $tokens, int $classIndex): bool
    {
        $tokensAnalyzer = new TokensAnalyzer($tokens);
        foreach ($tokensAnalyzer->getClassyElements() as $element) {
            if ($element['classIndex'] !== $classIndex) {
                continue;
            }

            if (in_array($element['type'], ['method', 'const'], true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function parseTargets(string $doc): array
    {
        if (!Preg::match('/@Target\s*\(\s*\{([^}]+)\}\s*\)/', $doc, $matches)) {
            return ['Attribute::TARGET_ALL'];
        }

        $targets = [];
        if (Preg::matchAll('/"([A-Z_]+)"/', $matches[1], $found)) {
            foreach ($found[1] as $target) {
                $targets[] = 'Attribute::TARGET_'.$target;
            }
        }

        return $targets !== [] ? $targets : ['Attribute::TARGET_ALL'];
    }

    /**
     * @return list<array{name: string, type: string, start: int, end: int}>
     */
    private function collectPublicProperties(Tokens $tokens, int $classIndex): array
    {
        $properties = [];
        $tokensAnalyzer = new TokensAnalyzer($tokens);
        foreach ($tokensAnalyzer->getClassyElements() as $index => $element) {
            if ($element['classIndex'] !== $classIndex || $element['type'] !== 'property') {
                continue;
            }

            $varIndex = $index;
            $visibility = $this->findVisibility($tokens, $varIndex);
            if ($visibility !== 'public') {
                continue;
            }

            $type = $this->readPropertyType($tokens, $varIndex) ?? 'mixed';
            $name = substr($element['token']->getContent(), 1);
            $end = $this->findPropertyStatementEnd($tokens, $varIndex);

            $properties[] = [
                'name' => $name,
                'type' => $type,
                'start' => $this->findPropertyStatementStart($tokens, $varIndex),
                'end' => $end,
            ];
        }

        return $properties;
    }

    private function findVisibility(Tokens $tokens, int $varIndex): ?string
    {
        $prev = $tokens->getPrevMeaningfulToken($varIndex);
        while (is_int($prev)) {
            if ($tokens[$prev]->isGivenKind(\T_PUBLIC)) {
                return 'public';
            }

            if ($tokens[$prev]->isGivenKind(\T_PROTECTED)) {
                return 'protected';
            }

            if ($tokens[$prev]->isGivenKind(\T_PRIVATE)) {
                return 'private';
            }

            if ($tokens[$prev]->equals('{') || $tokens[$prev]->equals(';')) {
                return null;
            }

            $prev = $tokens->getPrevMeaningfulToken($prev);
        }

        return null;
    }

    private function readPropertyType(Tokens $tokens, int $varIndex): ?string
    {
        $type = '';
        $index = $tokens->getPrevMeaningfulToken($varIndex);
        while (is_int($index)) {
            if ($tokens[$index]->isGivenKind([\T_PUBLIC, \T_PROTECTED, \T_PRIVATE, \T_VAR, \T_STATIC, \T_DOC_COMMENT])) {
                break;
            }

            if ($tokens[$index]->equals('{') || $tokens[$index]->equals(';') || $tokens[$index]->equals(',')) {
                break;
            }

            $type = $tokens[$index]->getContent().$type;
            $index = $tokens->getPrevMeaningfulToken($index);
        }

        $type = trim($type);

        return $type !== '' ? $type : null;
    }

    private function findPropertyStatementStart(Tokens $tokens, int $varIndex): int
    {
        $start = $varIndex;
        $prev = $tokens->getPrevTokenOfKind($varIndex, [[\T_DOC_COMMENT], [\T_PUBLIC], [\T_PROTECTED], [\T_PRIVATE], [\T_VAR]]);
        if (is_int($prev)) {
            $start = $prev;
        }

        return $start;
    }

    private function findPropertyStatementEnd(Tokens $tokens, int $varIndex): int
    {
        $next = $tokens->getNextTokenOfKind($varIndex, [';']);
        if (!is_int($next)) {
            return $varIndex;
        }

        return $next;
    }

    /**
     * @param list<string> $targets
     */
    private function insertClassAttribute(Tokens $tokens, int $classIndex, array $targets): void
    {
        $targetExpr = count($targets) === 1
            ? str_replace('Attribute::', '\\Attribute::', $targets[0])
            : implode(' | ', array_map(
                static fn (string $target): string => str_replace('Attribute::', '\\Attribute::', $target),
                $targets,
            ));

        $attribute = '#[\\Attribute('.$targetExpr.')]'."\n";

        $tokens->insertAt($classIndex, [
            new Token([\T_WHITESPACE, $attribute]),
        ]);
    }

    private function replaceClassDocblock(Tokens $tokens, int $docIndex): void
    {
        $content = $tokens[$docIndex]->getContent();
        $lines = preg_split('/\r\n|\r|\n/', $content) ?: [];
        $kept = [];
        foreach ($lines as $line) {
            if (Preg::match('/@(Annotation|Target|NamedArgumentConstructor)\b/', $line)) {
                continue;
            }

            $kept[] = $line;
        }

        $newContent = implode("\n", $kept);
        if (Preg::match('/^\s*\/\*\*\s*\*\/\s*$/s', $newContent) || trim($newContent) === '/** */') {
            $tokens->clearAt($docIndex);

            return;
        }

        $tokens[$docIndex] = new Token([\T_DOC_COMMENT, $newContent]);
    }

    /**
     * @param list<array{name: string, type: string, start: int, end: int}> $properties
     */
    private function buildConstructorFromProperties(Tokens $tokens, int $classIndex, array $properties): void
    {
        if ($properties === []) {
            return;
        }

        foreach (array_reverse($properties) as $property) {
            $tokens->clearRange($property['start'], $property['end']);
        }

        $braceOpen = $tokens->getNextTokenOfKind($classIndex, ['{']);
        if (!is_int($braceOpen)) {
            return;
        }

        $params = [];
        foreach ($properties as $property) {
            $params[] = sprintf(
                'public %s $%s',
                $property['type'],
                $property['name'],
            );
        }
        $constructor = sprintf(
            "\n    public function __construct(%s) {}\n",
            implode(', ', $params),
        );

        $tokens->insertAt($braceOpen + 1, Token::fromCode($constructor));
    }
}
