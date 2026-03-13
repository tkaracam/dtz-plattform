<?php
declare(strict_types=1);

function training_template_bank_file(): string
{
    return dirname(__DIR__) . '/assets/content/dtz_lesen_hoeren_template_bank.json';
}

function load_training_template_bank(): array
{
    $file = training_template_bank_file();
    if (!is_file($file)) {
        throw new RuntimeException('Template-Bank wurde nicht gefunden.');
    }

    $raw = file_get_contents($file);
    if (!is_string($raw) || trim($raw) === '') {
        throw new RuntimeException('Template-Bank ist leer.');
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        throw new RuntimeException('Template-Bank enthält ungültiges JSON.');
    }

    return $decoded;
}

function normalize_training_module(string $module): string
{
    $value = mb_strtolower(trim($module));
    if ($value === 'lesen') {
        return 'lesen';
    }
    if ($value === 'hoeren' || $value === 'hören') {
        return 'hoeren';
    }
    return '';
}

function get_training_templates(string $module): array
{
    $bank = load_training_template_bank();
    $normalized = normalize_training_module($module);
    if ($normalized === '') {
        throw new RuntimeException('Ungültiges Modul angefordert.');
    }

    $key = $normalized === 'lesen' ? 'lesen_templates' : 'hoeren_templates';
    $templates = $bank[$key] ?? [];
    if (!is_array($templates)) {
        throw new RuntimeException('Template-Liste ist ungültig.');
    }

    $clean = [];
    foreach ($templates as $tpl) {
        if (!is_array($tpl)) {
            continue;
        }
        $sample = $tpl['sample_item'] ?? null;
        $options = is_array($sample['options'] ?? null) ? $sample['options'] : null;
        if (!is_array($sample) || !is_array($options)) {
            continue;
        }
        if (!isset($options['A'], $options['B'], $options['C'])) {
            continue;
        }
        $correct = trim((string)($sample['correct'] ?? ''));
        if (!in_array($correct, ['A', 'B', 'C'], true)) {
            continue;
        }
        $clean[] = $tpl;
    }

    return $clean;
}

function clamp_training_count(string $module, int $count): int
{
    $normalized = normalize_training_module($module);
    $max = $normalized === 'lesen' ? 40 : 30;
    if ($count < 1) {
        $count = 1;
    }
    if ($count > $max) {
        $count = $max;
    }
    return $count;
}

function shuffle_abc_options(array $options, string $correct): array
{
    $pairs = [
        ['label' => 'A', 'text' => (string)($options['A'] ?? '')],
        ['label' => 'B', 'text' => (string)($options['B'] ?? '')],
        ['label' => 'C', 'text' => (string)($options['C'] ?? '')],
    ];

    for ($i = count($pairs) - 1; $i > 0; $i--) {
        try {
            $j = random_int(0, $i);
        } catch (Throwable $e) {
            $j = mt_rand(0, $i);
        }
        $tmp = $pairs[$i];
        $pairs[$i] = $pairs[$j];
        $pairs[$j] = $tmp;
    }

    $outOptions = [];
    $letters = ['A', 'B', 'C'];
    $newCorrect = 'A';
    foreach ($pairs as $index => $pair) {
        $letter = $letters[$index];
        $outOptions[$letter] = (string)($pair['text'] ?? '');
        if ((string)$pair['label'] === $correct) {
            $newCorrect = $letter;
        }
    }

    return [
        'options' => $outOptions,
        'correct' => $newCorrect,
    ];
}

function create_training_set(string $module, int $count, bool $includeExplanation): array
{
    $templates = get_training_templates($module);
    if (!$templates) {
        throw new RuntimeException('Keine gültigen Templates gefunden.');
    }

    $count = clamp_training_count($module, $count);
    if ($count > count($templates)) {
        $count = count($templates);
    }

    $pool = array_values($templates);
    for ($i = count($pool) - 1; $i > 0; $i--) {
        try {
            $j = random_int(0, $i);
        } catch (Throwable $e) {
            $j = mt_rand(0, $i);
        }
        $tmp = $pool[$i];
        $pool[$i] = $pool[$j];
        $pool[$j] = $tmp;
    }
    $picked = array_slice($pool, 0, $count);

    $items = [];
    foreach ($picked as $index => $tpl) {
        $sample = $tpl['sample_item'];
        $shuffled = shuffle_abc_options($sample['options'], (string)$sample['correct']);
        $items[] = [
            'set_index' => $index + 1,
            'template_id' => (string)($tpl['id'] ?? ''),
            'dtz_part' => (string)($tpl['dtz_part'] ?? ''),
            'task_type' => (string)($tpl['task_type'] ?? ''),
            'context' => (string)($tpl['context'] ?? ''),
            'title' => (string)($tpl['title'] ?? ''),
            'instructions' => (string)($tpl['instructions'] ?? ''),
            'text' => (string)($sample['text'] ?? $sample['audio_script'] ?? ''),
            'question' => (string)($sample['question'] ?? ''),
            'options' => $shuffled['options'],
            'correct' => (string)$shuffled['correct'],
            'explanation' => $includeExplanation ? (string)($sample['rationale'] ?? '') : '',
        ];
    }

    return [
        'module' => normalize_training_module($module),
        'count' => count($items),
        'include_explanation' => $includeExplanation,
        'generated_at' => gmdate('c'),
        'items' => $items,
    ];
}
