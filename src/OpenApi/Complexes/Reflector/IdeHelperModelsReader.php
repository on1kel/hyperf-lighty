<?php

declare(strict_types = 1);

namespace On1kel\HyperfLighty\OpenApi\Complexes\Reflector;

use Hyperf\Contract\ConfigInterface;
use Psr\SimpleCache\CacheInterface;
use Psr\SimpleCache\InvalidArgumentException;

final class IdeHelperModelsReader implements IdeHelperModelsReaderInterface
{
    public function __construct(
        private readonly ConfigInterface $config,
        private readonly ?CacheInterface $cache = null,
    ) {
    }

    /**
     * @throws InvalidArgumentException
     */
    public function getPropertiesMap(): array
    {
        if (! $this->config->get('lighty.ide_helper.enabled', true)) {
            return [];
        }


        $file = BASE_PATH .'/'.(string) $this->config->get('lighty.ide_helper.models_file',  '_ide_helper_models.php');
        if (! file_exists($file)) {
            throw new \RuntimeException("Файл ide-helper не найден по пути: {$file}");
        }
        if (! is_readable($file)) {
            return [];
        }

        $cacheKeyRoot = (string) $this->config->get('lighty.ide_helper.cache_key', 'lighty.ide_helper.models.parsed');
        $ttl = (int) $this->config->get('lighty.ide_helper.cache_ttl', 86400);
        $fingerprint = $this->fingerprint($file);
        $cacheKey = $cacheKeyRoot . '.' . $fingerprint;

        if ($this->cache) {
            $cached = $this->cache->get($cacheKey);
            //            if (is_array($cached)) {
            //                return $cached;
            //            }
        }
        $content = file_get_contents($file);
        if ($content === false || $content === '') {
            return [];
        }

        $map = $this->parse($content);

        $this->cache?->set($cacheKey, $map, $ttl);

        return $map;
    }

    // ===================== internals =====================

    private function fingerprint(string $file): string
    {
        $stat = @stat($file);
        $mtime = (int) ($stat['mtime'] ?? @filemtime($file) ?: 0);
        $size = (int) ($stat['size'] ?? @filesize($file) ?: 0);

        return hash('sha256', $file . '|' . $mtime . '|' . $size);
    }

    private function parse(string $content): array
    {
        $result = [];

        // 1) Блочные namespace: namespace X\Y { ... }
        $nsBlock = '/namespace\s+([^;{]+)\s*\{(.*?)\}/su';
        if (preg_match_all($nsBlock, $content, $blocks, PREG_SET_ORDER)) {
            foreach ($blocks as $m) {
                $ns = $this->normalizeNamespace($m[1] ?? '');
                $src = $m[2] ?? '';
                $this->parseClassesInChunk($src, $ns, $result);
            }
        }

        // 2) Точечные namespace: namespace X\Y;
        //    Разбиваем на секции "namespace ...;" → "до следующего namespace"
        $nsSemi = '/namespace\s+([^;{]+)\s*;\s*/su';
        $parts = preg_split($nsSemi, $content, -1, PREG_SPLIT_DELIM_CAPTURE);
        if ($parts && \count($parts) >= 3) {
            for ($i = 1; $i + 1 < \count($parts); $i += 2) {
                $ns = $this->normalizeNamespace($parts[$i] ?? '');
                $src = $parts[$i + 1] ?? '';
                $this->parseClassesInChunk($src, $ns, $result);
            }
        }

        // 3) Фолбэк: пробегаем весь файл, ищем /** ... */ class X, берём ближайший namespace выше
        if (empty($result)) {
            $docClass = '/(\/\*\*.*?\*\/)\s*class\s+([A-Za-z_][A-Za-z0-9_]*)\b/su';
            if (preg_match_all($docClass, $content, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
                foreach ($matches as $m) {
                    $doc = $m[1][0] ?? '';
                    $name = $m[2][0] ?? '';
                    $pos = $m[0][1] ?? 0;
                    if ($doc === '' || $name === '') {
                        continue;
                    }

                    $ns = $this->findNearestNamespaceAbove($content, $pos);
                    if ($ns === '') {
                        $ns = '\\';
                    } // глобальный

                    $fqcn = ltrim($ns . '\\' . $name, '\\');
                    $props = $this->parseDocblock($doc);
                    if ($props) {
                        $result[$fqcn] = $props;
                    }
                }
            }
        }

        return $result;
    }

    private function parseClassesInChunk(string $src, string $ns, array &$out): void
    {
        if ($ns === '' || $src === '') {
            return;
        }

        $classPattern = '/(\/\*\*[\s\S]*?\*\/)\s*(?:(?:final|abstract|readonly)\s+)*class\s+([A-Za-z_][A-Za-z0-9_]*)\b/su';
        if (! preg_match_all($classPattern, $src, $classes, PREG_SET_ORDER)) {
            return;
        }

        foreach ($classes as $cm) {
            $doc = $cm[1] ?? '';
            $name = $cm[2] ?? '';
            if ($doc === '' || $name === '') {
                continue;
            }

            $fqcn = ltrim($ns . '\\' . $name, '\\');
            $props = $this->parseDocblock($doc);
            if ($props) {
                $out[$fqcn] = $props;
            }
        }
    }

    /**
     * Ищем ближайшее "namespace Foo\Bar[;|{]" выше позиции $pos.
     */
    private function findNearestNamespaceAbove(string $content, int $pos): string
    {
        $chunk = substr($content, 0, max(0, $pos));
        $pattern = '/namespace\s+([^;{]+)\s*[;{]/su';
        if (preg_match_all($pattern, $chunk, $all, PREG_SET_ORDER)) {
            $last = end($all);

            return $this->normalizeNamespace($last[1] ?? '');
        }

        return '';
    }

    private function normalizeNamespace(string $raw): string
    {
        $raw = trim(str_replace([' ', "\t", "\r", "\n", '/'], ['', '', '', '', '\\'], $raw));
        $raw = rtrim($raw, ';');
        if ($raw === '') {
            return '';
        }
        if ($raw[0] !== '\\') {
            $raw = '\\' . $raw;
        }

        return $raw;
    }

    private function parseDocblock(string $doc): array
    {
        $lines = preg_split('/\R/u', $doc) ?: [];
        $out = [];

        foreach ($lines as $line) {
            $line = trim($line, " \t\n\r\0\x0B*");
            if ($line === '' || (strpos($line, '@property') === false && strpos($line, '@property-read') === false)) {
                continue;
            }

            // @property-read \Hyperf\Database\Model\Collection|\App\Model\Comment[] $comments
            if (! preg_match('/^@(?P<kind>property(?:-read)?)\s+(?P<type>.+?)\s+\$(?P<name>[A-Za-z_][A-Za-z0-9_]*)\s*(?P<desc>.*)$/u', $line, $m)) {
                continue;
            }

            $rawType = trim($m['type']);
            $name = trim($m['name']);
            $desc = trim($m['desc'] ?? '');
            $ro = ($m['kind'] === 'property-read');

            // НЕ обрезаем union-тип до первого — сохраняем строку целиком, но выносим nullable
            [$type, $nullable] = $this->normalizeTypePreserveUnion($rawType);

            $out[] = [
                'name' => $name,
                'type' => $type,      // например: "\Hyperf\Database\Model\Collection|\App\Model\Comment[]"
                'nullable' => $nullable,
                'description' => $desc,
                'readonly' => $ro,
            ];
        }

        return $out;
    }

    private function normalizeTypePreserveUnion(string $raw): array
    {
        $raw = trim($raw);
        $nullable = false;

        if ($raw !== '' && $raw[0] === '?') {
            $nullable = true;
            $raw = substr($raw, 1);
        }

        $parts = array_values(array_filter(array_map('trim', explode('|', $raw))));
        if ($parts && array_filter($parts, static fn ($t) => strcasecmp($t, 'null') === 0)) {
            $nullable = true;
            $parts = array_values(array_filter($parts, static fn ($t) => strcasecmp($t, 'null') !== 0));
        }

        return [implode('|', $parts) ?: 'mixed', $nullable];
    }
}
