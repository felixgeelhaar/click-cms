<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Click\Cms\Domain\ValueObjects\PluginId;
use Click\Cms\Domain\ValueObjects\PluginVersion;
use Click\Cms\Domain\ValueObjects\ContentKey;

echo "Running basic tests...\n\n";

// Test PluginId
echo "1. Testing PluginId...\n";
$id = PluginId::fromString('test-plugin');
assert($id->value === 'test-plugin', 'PluginId fromString failed');
echo "   ✓ fromString works\n";

$id2 = PluginId::generate('My Test Plugin');
assert($id2->value === 'my-test-plugin', 'PluginId generate failed');
echo "   ✓ generate works\n";

// Test PluginVersion
echo "\n2. Testing PluginVersion...\n";
$version = PluginVersion::fromString('1.0.0');
assert($version->value === '1.0.0', 'PluginVersion fromString failed');
echo "   ✓ fromString works\n";

// Test ContentKey
echo "\n3. Testing ContentKey...\n";
$key = ContentKey::page('home');
assert($key->type === 'page', 'ContentKey type failed');
assert($key->slug === 'home', 'ContentKey slug failed');
assert($key->toString() === 'page:home', 'ContentKey toString failed');
echo "   ✓ page() works\n";

$key2 = ContentKey::fromString('user:john');
assert($key2->type === 'user', 'ContentKey fromString type failed');
assert($key2->slug === 'john', 'ContentKey fromString slug failed');
echo "   ✓ fromString works\n";

echo "\n✅ All basic tests passed!\n";
