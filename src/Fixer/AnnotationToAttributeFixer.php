<?php

declare(strict_types=1);

namespace PhpConverter\Fixer;

use PhpCsFixer\Fixer\ConfigurableFixerInterface;
use PhpCsFixer\FixerConfiguration\FixerConfigurationResolver;
use PhpCsFixer\FixerConfiguration\FixerConfigurationResolverInterface;
use PhpCsFixer\FixerConfiguration\FixerOptionBuilder;
use PhpCsFixer\FixerDefinition\CodeSample;
use PhpCsFixer\FixerDefinition\FixerDefinition;
use PhpCsFixer\FixerDefinition\FixerDefinitionInterface;
use PhpCsFixer\Preg;
use PhpCsFixer\Tokenizer\Analyzer\Analysis\NamespaceAnalysis;
use PhpCsFixer\Tokenizer\Analyzer\NamespaceUsesAnalyzer;
use PhpCsFixer\Tokenizer\Token;
use PhpCsFixer\Tokenizer\Tokens;

final class AnnotationToAttributeFixer extends AbstractPhpConverterFixer implements ConfigurableFixerInterface
{
    /** @var array<string, string> */
    private array $annotationMap = [];

    /** @var array<string, string> */
    private array $customAnnotations = [];

    public static function name(): string
    {
        return 'PhpConverter/annotation_to_attribute';
    }

    protected function ruleName(): string
    {
        return 'annotation_to_attribute';
    }

    public function getDefinition(): FixerDefinitionInterface
    {
        return new FixerDefinition(
            'Convert docblock annotations to PHP 8 attributes (Doctrine/Symfony and configured custom classes).',
            [
                new CodeSample(
                    <<<'PHP'
                    <?php

                    use Doctrine\ORM\Mapping as ORM;

                    /**
                     * @ORM\Entity
                     * @ORM\Table(name="users")
                     */
                    class User {}
                    PHP,
                ),
            ],
        );
    }

    public function getConfigurationDefinition(): FixerConfigurationResolverInterface
    {
        return new FixerConfigurationResolver([
            (new FixerOptionBuilder('annotation_map', 'Map of annotation name to attribute FQCN.'))
                ->setAllowedTypes(['array'])
                ->setDefault([])
                ->getOption(),
            (new FixerOptionBuilder('custom_annotations', 'Map of annotation FQCN to attribute FQCN.'))
                ->setAllowedTypes(['array'])
                ->setDefault([])
                ->getOption(),
        ]);
    }

    /**
     * @param array<string, mixed> $configuration
     */
    public function configure(array $configuration): void
    {
        $this->annotationMap = $configuration['annotation_map'] ?? [];
        $this->customAnnotations = $configuration['custom_annotations'] ?? [];
    }

    public function isCandidate(Tokens $tokens): bool
    {
        return $tokens->isTokenKindFound(\T_DOC_COMMENT);
    }

    public function getPriority(): int
    {
        return 50;
    }

    protected function fixFile(\SplFileInfo $file, Tokens $tokens): void
    {
        $usesAnalyzer = new NamespaceUsesAnalyzer();
        $namespaces = $usesAnalyzer->getDeclarations($tokens);

        for ($index = $tokens->count() - 1; $index > 0; --$index) {
            if (!$tokens[$index]->isGivenKind(\T_DOC_COMMENT)) {
                continue;
            }

            $structureIndex = $this->findAnnotatedStructureIndex($tokens, $index);
            if ($structureIndex === null) {
                continue;
            }

            $namespace = $this->resolveNamespace($tokens, $structureIndex, $namespaces);
            $useMap = $this->buildUseMap($tokens, $namespace, $namespaces);

            $doc = $tokens[$index]->getContent();
            $annotations = $this->extractAnnotations($doc);
            if ($annotations === []) {
                continue;
            }

            $attributes = [];
            $remainingDoc = $doc;

            foreach ($annotations as $annotation) {
                $attribute = $this->convertAnnotationToAttribute($annotation, $useMap);
                if ($attribute === null) {
                    continue;
                }

                if ($this->containsNonLiteralExpression($annotation['raw'])) {
                    continue;
                }

                $attributes[] = $attribute;
                $remainingDoc = str_replace($annotation['raw'], '', $remainingDoc);
            }

            if ($attributes === []) {
                continue;
            }

            $attributeTokens = Token::fromCode(implode("\n", $attributes)."\n");
            $tokens->insertAt($structureIndex, $attributeTokens);

            $this->updateDocComment($tokens, $index, $remainingDoc);
        }
    }

    private function findAnnotatedStructureIndex(Tokens $tokens, int $docIndex): ?int
    {
        $next = $tokens->getNextMeaningfulToken($docIndex);
        if (!is_int($next)) {
            return null;
        }

        if ($tokens[$next]->isGivenKind([\T_CLASS, \T_INTERFACE, \T_TRAIT, \T_FUNCTION, \T_VARIABLE, \T_ABSTRACT, \T_FINAL])) {
            if ($tokens[$next]->isGivenKind([\T_ABSTRACT, \T_FINAL])) {
                $next = $tokens->getNextMeaningfulToken($next);
            }

            return is_int($next) ? $next : null;
        }

        return null;
    }

    /**
     * @param list<NamespaceAnalysis> $namespaces
     */
    private function resolveNamespace(Tokens $tokens, int $index, array $namespaces): ?string
    {
        for ($i = $index; $i >= 0; --$i) {
            if ($tokens[$i]->isGivenKind(\T_NAMESPACE)) {
                $nameIndex = $tokens->getNextMeaningfulToken($i);
                if (!is_int($nameIndex)) {
                    return null;
                }

                $parts = [];
                while ($nameIndex < $tokens->count() && !$tokens[$nameIndex]->equals(';')) {
                    if ($tokens[$nameIndex]->isGivenKind([\T_STRING, \T_NS_SEPARATOR])) {
                        $parts[] = $tokens[$nameIndex]->getContent();
                    }

                    ++$nameIndex;
                }

                return implode('', $parts);
            }
        }

        return null;
    }

    /**
     * @param list<NamespaceAnalysis> $namespaces
     *
     * @return array<string, string>
     */
    private function buildUseMap(Tokens $tokens, ?string $namespace, array $namespaces): array
    {
        $map = [];
        foreach ($namespaces as $declaration) {
            if ($namespace !== null && $declaration->getNamespaceName() !== $namespace) {
                continue;
            }

            foreach ($declaration->getUses() as $use) {
                $map[$use->getAlias()] = $use->getFullName();
            }
        }

        return $map;
    }

    /**
     * @return list<array{raw: string, name: string, args: string}>
     */
    private function extractAnnotations(string $doc): array
    {
        $annotations = [];
        if (!Preg::matchAll('/@\(?([A-Za-z0-9_\\\\]+)(?:\(([^)]*)\))?\)?/', $doc, $matches, PREG_SET_ORDER)) {
            return [];
        }

        foreach ($matches as $match) {
            $name = $match[1];
            if (in_array($name, ['param', 'return', 'var', 'throws', 'inheritdoc', 'see', 'since', 'Annotation', 'Target'], true)) {
                continue;
            }

            $annotations[] = [
                'raw' => $match[0],
                'name' => $name,
                'args' => $match[2] ?? '',
            ];
        }

        return $annotations;
    }

    /**
     * @param array<string, string> $useMap
     */
    private function convertAnnotationToAttribute(array $annotation, array $useMap): ?string
    {
        $name = $annotation['name'];
        $fqcn = $this->annotationMap[$name] ?? $this->resolveFqcn($name, $useMap);

        if (isset($this->customAnnotations[$fqcn])) {
            $fqcn = $this->customAnnotations[$fqcn];
        } elseif (!isset($this->annotationMap[$name]) && !str_contains($name, '\\') && !isset($useMap[$name])) {
            return null;
        }

        $shortName = $this->shortName($fqcn, $useMap);
        $args = trim($annotation['args']);

        if ($args === '') {
            return '#['.$shortName.']';
        }

        $convertedArgs = $this->convertAnnotationArguments($args);

        return '#['.$shortName.'('.$convertedArgs.')]';
    }

    /**
     * @param array<string, string> $useMap
     */
    private function resolveFqcn(string $name, array $useMap): string
    {
        if (str_contains($name, '\\')) {
            return ltrim($name, '\\');
        }

        if (isset($useMap[$name])) {
            return $useMap[$name];
        }

        if (isset($this->annotationMap[$name])) {
            return $this->annotationMap[$name];
        }

        return $name;
    }

    /**
     * @param array<string, string> $useMap
     */
    private function shortName(string $fqcn, array $useMap): string
    {
        foreach ($useMap as $alias => $full) {
            if ($fqcn === $full) {
                return $alias;
            }

            if (str_starts_with($fqcn, $full.'\\')) {
                return $alias.'\\'.substr($fqcn, strlen($full) + 1);
            }
        }

        if (str_contains($fqcn, '\\')) {
            $parts = explode('\\', $fqcn);

            return end($parts);
        }

        return $fqcn;
    }

    private function convertAnnotationArguments(string $args): string
    {
        $args = preg_replace('/=\s*/', ': ', $args) ?? $args;
        $args = preg_replace('/,\s*/', ', ', $args) ?? $args;

        return $args;
    }

    private function containsNonLiteralExpression(string $raw): bool
    {
        return Preg::match('/\$|->|\(function|\bnew\b/', $raw) === 1;
    }

    private function updateDocComment(Tokens $tokens, int $docIndex, string $content): void
    {
        $lines = preg_split('/\r\n|\r|\n/', $content) ?: [];
        $filtered = array_filter($lines, static fn (string $line): bool => trim($line, " \t*") !== '');

        if (count($filtered) <= 2) {
            $tokens->clearAt($docIndex);

            return;
        }

        $tokens[$docIndex] = new Token([\T_DOC_COMMENT, implode("\n", $filtered)]);
    }
}
