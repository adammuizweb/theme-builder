<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/class-var-reference.php';

$reference = VarReference::forSlot('main.homepage');
$panel = VarReference::renderPanel('main.homepage');
$failures = [];
$check = static function (bool $ok, string $message) use (&$failures): void {
    echo ($ok ? 'PASS ' : 'FAIL ') . $message . PHP_EOL;
    if (!$ok) $failures[] = $message;
};

$check(($reference['common']['$context']['type'] ?? null) === 'array', '$context is documented as an array');
$check(isset($reference['protected']['$__jy_theme_folder'], $reference['protected']['$__jy_theme_source_folder'],
    $reference['protected']['$__jy_slot_key']), 'Core-protected slot metadata is documented');
$check(isset($reference['theme_section']['$attrs'], $reference['theme_section']['$context'], $reference['theme_section']['$pdo']),
    'Theme Section renderer variables are documented');
$check(isset($reference['helpers']['widget("name", [...], $pdo)'])
    && isset($reference['helpers']['render_sidebar_widgets($pdo, $zone ?? null)'])
    && isset($reference['helpers']['render_shortcode_preset($pdo, "preset-slug", $overrides, $context)']),
    'widget PDO, sidebar chain, and request-local Preset override signatures are documented');
$check(str_contains($panel, 'Protected Slot Metadata') && str_contains($panel, 'Theme Sections'),
    'rendered reference panel exposes modern slot and Theme Section guidance');

if ($failures !== []) {
    fwrite(STDERR, 'Var reference contract failed: ' . implode('; ', $failures) . PHP_EOL);
    exit(1);
}
echo "RESULT: ALL PASS\n";
