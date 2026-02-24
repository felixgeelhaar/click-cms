<?php

declare(strict_types=1);

use Click\Cms\Application\Plugin\BasePlugin;
use Click\Cms\Domain\Content\Content;

class Plugin_visual_builder extends BasePlugin
{
    public function getPluginId(): string
    {
        return 'visual-builder';
    }

    public function getPluginName(): string
    {
        return 'Visual Builder';
    }

    public function hook_web_render(array $params): ?string
    {
        $page = $params['page'] ?? null;
        if (!$page instanceof Content) {
            return null;
        }

        $data = $page->data ?? [];
        $builder = $data['builder'] ?? null;
        if (!is_array($builder)) {
            return null;
        }

        $title = htmlspecialchars($page->title(), ENT_QUOTES, 'UTF-8');
        $body = $this->renderBuilder($builder);

        return '<!doctype html><html><head><meta charset="utf-8"><title>' . $title . '</title></head><body>' . $body . '</body></html>';
    }

    private function renderBuilder(array $builder): string
    {
        $nodes = $builder['nodes'] ?? [];
        $rootId = $builder['root'] ?? null;
        if (!is_string($rootId) || !isset($nodes[$rootId])) {
            return '';
        }
        $responsiveStyles = $this->buildResponsiveStyles($nodes, $builder['breakpoints'] ?? []);
        $styleTag = $responsiveStyles !== '' ? '<style>' . $responsiveStyles . '</style>' : '';

        return $styleTag . $this->renderNode($nodes, $rootId);
    }

    private function renderNode(array $nodes, string $id): string
    {
        $node = $nodes[$id] ?? null;
        if (!is_array($node)) {
            return '';
        }

        $type = $node['type'] ?? 'section';
        $children = $node['children'] ?? [];
        $props = $node['props'] ?? [];
        $styles = $node['styles'] ?? [];
        $responsive = $node['responsive']['base'] ?? null;
        if (is_array($responsive)) {
            $props = array_merge($props, $responsive['props'] ?? []);
            $styles = array_merge($styles, $responsive['styles'] ?? []);
        }

        if ($type === 'grid' && isset($props['columns']) && !isset($styles['gridTemplateColumns'])) {
            $styles['display'] = $styles['display'] ?? 'grid';
            $styles['gridTemplateColumns'] = 'repeat(' . (int) $props['columns'] . ', minmax(0, 1fr))';
        }

        $styleAttr = $this->styleString($styles);
        $dataAttr = ' data-node-id="' . htmlspecialchars($id, ENT_QUOTES, 'UTF-8') . '"';
        $inner = '';
        foreach ($children as $childId) {
            if (!is_string($childId)) {
                continue;
            }
            $inner .= $this->renderNode($nodes, $childId);
        }

        if ($type === 'text') {
            $text = htmlspecialchars((string)($props['text'] ?? ''), ENT_QUOTES, 'UTF-8');
            return '<p' . $dataAttr . $styleAttr . '>' . $text . '</p>';
        }

        if ($type === 'image') {
            $src = htmlspecialchars((string)($props['src'] ?? ''), ENT_QUOTES, 'UTF-8');
            $alt = htmlspecialchars((string)($props['alt'] ?? ''), ENT_QUOTES, 'UTF-8');
            return '<img src="' . $src . '" alt="' . $alt . '"' . $dataAttr . $styleAttr . ' />';
        }

        if ($type === 'button') {
            $label = htmlspecialchars((string)($props['label'] ?? 'Button'), ENT_QUOTES, 'UTF-8');
            $href = htmlspecialchars((string)($props['href'] ?? '#'), ENT_QUOTES, 'UTF-8');
            return '<a href="' . $href . '"' . $dataAttr . $styleAttr . '>' . $label . '</a>';
        }

        if ($type === 'spacer') {
            return '<div' . $dataAttr . $styleAttr . '></div>';
        }

        if ($type === 'chart') {
            return $this->renderChart($props, $styleAttr, $dataAttr);
        }

        $tag = $type === 'grid' ? 'div' : 'section';
        return '<' . $tag . $dataAttr . $styleAttr . '>' . $inner . '</' . $tag . '>';
    }

    private function styleString(array $styles): string
    {
        if (empty($styles)) {
            return '';
        }

        $pairs = [];
        foreach ($styles as $key => $value) {
            if (!is_scalar($value)) {
                continue;
            }
            $cssKey = $this->toCssProperty((string) $key);
            $pairs[] = $cssKey . ':' . $value;
        }

        if (empty($pairs)) {
            return '';
        }

        return ' style="' . htmlspecialchars(implode(';', $pairs), ENT_QUOTES, 'UTF-8') . '"';
    }

    private function toCssProperty(string $key): string
    {
        if (str_contains($key, '-')) {
            return $key;
        }

        $kebab = preg_replace('/([a-z0-9])([A-Z])/', '$1-$2', $key);
        if ($kebab === null) {
            return $key;
        }

        return strtolower($kebab);
    }

    private function renderChart(array $props, string $styleAttr, string $dataAttr): string
    {
        $chartType = (string)($props['chartType'] ?? 'bar');
        $title = trim((string)($props['title'] ?? ''));
        $color = (string)($props['color'] ?? '#0ea5a4');
        $width = max(240, (int)($props['width'] ?? 640));
        $height = max(160, (int)($props['height'] ?? 280));

        $data = $props['data'] ?? [];
        $points = [];
        if (is_array($data)) {
            foreach ($data as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $label = (string)($item['label'] ?? '');
                $value = (float)($item['value'] ?? 0);
                $points[] = ['label' => $label, 'value' => $value];
            }
        }

        $titleHtml = '';
        if ($title !== '') {
            $titleHtml = '<div style="margin-bottom:12px;font-weight:600;">' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</div>';
        }

        $svg = $this->renderChartSvg($chartType, $points, $width, $height, $color, $title);
        return '<div' . $dataAttr . $styleAttr . '>' . $titleHtml . $svg . '</div>';
    }

    private function buildResponsiveStyles(array $nodes, array $breakpoints): string
    {
        if (empty($nodes) || empty($breakpoints)) {
            return '';
        }

        $rulesByBreakpoint = [];
        foreach ($breakpoints as $breakpoint) {
            $id = $breakpoint['id'] ?? null;
            $minWidth = $breakpoint['minWidth'] ?? null;
            if (!is_string($id) || $id === 'base' || !is_numeric($minWidth)) {
                continue;
            }
            $rulesByBreakpoint[$id] = ['minWidth' => (int) $minWidth, 'rules' => []];
        }

        foreach ($nodes as $nodeId => $node) {
            if (!is_array($node)) {
                continue;
            }
            foreach ($rulesByBreakpoint as $breakpointId => $entry) {
                $responsive = $node['responsive'][$breakpointId] ?? null;
                if (!is_array($responsive)) {
                    continue;
                }

                $styles = $responsive['styles'] ?? [];
                $props = $responsive['props'] ?? [];
                if (($node['type'] ?? '') === 'grid' && isset($props['columns'])) {
                    $styles['display'] = $styles['display'] ?? 'grid';
                    $styles['gridTemplateColumns'] = 'repeat(' . (int) $props['columns'] . ', minmax(0, 1fr))';
                }

                if (!is_array($styles) || empty($styles)) {
                    continue;
                }

                $pairs = [];
                foreach ($styles as $key => $value) {
                    if (!is_scalar($value)) {
                        continue;
                    }
                    $pairs[] = $this->toCssProperty((string) $key) . ':' . $value;
                }

                if (empty($pairs)) {
                    continue;
                }

                $rulesByBreakpoint[$breakpointId]['rules'][] = '[data-node-id="' . addslashes((string) $nodeId) . '"]{' . implode(';', $pairs) . '}';
            }
        }

        $css = '';
        foreach ($rulesByBreakpoint as $entry) {
            if (empty($entry['rules'])) {
                continue;
            }
            $css .= '@media (min-width:' . $entry['minWidth'] . 'px){' . implode('', $entry['rules']) . '}';
        }

        return $css;
    }

    private function renderChartSvg(string $chartType, array $points, int $width, int $height, string $color, string $title): string
    {
        $margin = ['top' => 24, 'right' => 20, 'bottom' => 32, 'left' => 36];
        $innerWidth = max(1, $width - $margin['left'] - $margin['right']);
        $innerHeight = max(1, $height - $margin['top'] - $margin['bottom']);
        $maxValue = 1;
        foreach ($points as $point) {
            $maxValue = max($maxValue, (float)($point['value'] ?? 0));
        }

        $aria = $title !== '' ? htmlspecialchars($title, ENT_QUOTES, 'UTF-8') : 'Chart';
        $svg = '<svg width="100%" height="' . $height . '" viewBox="0 0 ' . $width . ' ' . $height . '" role="img" aria-label="' . $aria . '">';
        $svg .= '<rect x="0" y="0" width="' . $width . '" height="' . $height . '" fill="transparent" />';

        if (empty($points)) {
            $svg .= '<text x="' . ($width / 2) . '" y="' . ($height / 2) . '" text-anchor="middle" fill="#94a3b8" font-size="12">Add chart data</text>';
            $svg .= '</svg>';
            return $svg;
        }

        if ($chartType === 'line') {
            $step = count($points) > 1 ? $innerWidth / (count($points) - 1) : 0;
            $path = '';
            $circles = '';
            $labels = '';
            foreach ($points as $index => $point) {
                $x = $margin['left'] + ($step * $index);
                $value = (float)($point['value'] ?? 0);
                $y = $margin['top'] + ($innerHeight - ($value / $maxValue) * $innerHeight);
                $path .= ($index === 0 ? 'M' : 'L') . $x . ' ' . $y . ' ';
                $circles .= '<circle cx="' . $x . '" cy="' . $y . '" r="3.5" fill="' . htmlspecialchars($color, ENT_QUOTES, 'UTF-8') . '" />';
                $label = htmlspecialchars((string)($point['label'] ?? ''), ENT_QUOTES, 'UTF-8');
                if ($label !== '') {
                    $labels .= '<text x="' . $x . '" y="' . ($margin['top'] + $innerHeight + 18) . '" text-anchor="middle" fill="#475569" font-size="11">' . $label . '</text>';
                }
            }
            $svg .= '<path d="' . trim($path) . '" fill="none" stroke="' . htmlspecialchars($color, ENT_QUOTES, 'UTF-8') . '" stroke-width="2" />';
            $svg .= $circles;
            $svg .= '<line x1="' . $margin['left'] . '" y1="' . ($margin['top'] + $innerHeight) . '" x2="' . ($margin['left'] + $innerWidth) . '" y2="' . ($margin['top'] + $innerHeight) . '" stroke="#cbd5e1" />';
            $svg .= '<line x1="' . $margin['left'] . '" y1="' . $margin['top'] . '" x2="' . $margin['left'] . '" y2="' . ($margin['top'] + $innerHeight) . '" stroke="#cbd5e1" />';
            $svg .= $labels;
            $svg .= '</svg>';
            return $svg;
        }

        $count = count($points);
        $slot = $count > 0 ? $innerWidth / $count : $innerWidth;
        $barWidth = max(6, $slot * 0.7);
        $gap = $slot - $barWidth;
        $bars = '';
        $labels = '';
        foreach ($points as $index => $point) {
            $value = (float)($point['value'] ?? 0);
            $barHeight = ($value / $maxValue) * $innerHeight;
            $x = $margin['left'] + ($slot * $index) + ($gap / 2);
            $y = $margin['top'] + ($innerHeight - $barHeight);
            $bars .= '<rect x="' . $x . '" y="' . $y . '" width="' . $barWidth . '" height="' . $barHeight . '" rx="6" fill="' . htmlspecialchars($color, ENT_QUOTES, 'UTF-8') . '" />';
            $label = htmlspecialchars((string)($point['label'] ?? ''), ENT_QUOTES, 'UTF-8');
            if ($label !== '') {
                $labels .= '<text x="' . ($x + ($barWidth / 2)) . '" y="' . ($margin['top'] + $innerHeight + 18) . '" text-anchor="middle" fill="#475569" font-size="11">' . $label . '</text>';
            }
        }

        $svg .= $bars;
        $svg .= '<line x1="' . $margin['left'] . '" y1="' . ($margin['top'] + $innerHeight) . '" x2="' . ($margin['left'] + $innerWidth) . '" y2="' . ($margin['top'] + $innerHeight) . '" stroke="#cbd5e1" />';
        $svg .= '<line x1="' . $margin['left'] . '" y1="' . $margin['top'] . '" x2="' . $margin['left'] . '" y2="' . ($margin['top'] + $innerHeight) . '" stroke="#cbd5e1" />';
        $svg .= $labels;
        $svg .= '</svg>';

        return $svg;
    }
}
