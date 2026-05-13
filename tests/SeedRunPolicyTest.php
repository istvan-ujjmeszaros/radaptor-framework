<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../classes/seeding/class.AbstractSeed.php';
require_once __DIR__ . '/../classes/seeding/class.SeedContext.php';
require_once __DIR__ . '/../classes/seeding/class.SeedRunner.php';

final class SeedRunPolicyTest extends TestCase
{
	public function testBootstrapOnceStatusAutoSkipsChangedSeedWithExistingMarker(): void
	{
		$seeds = [$this->seedDescriptor([
			'version' => '1.1.0',
			'run_policy' => AbstractSeed::RUN_POLICY_BOOTSTRAP_ONCE,
		])];
		$applied = [
			'app:SeedSkeletonBootstrap' => [
				'module' => 'app',
				'seed_class' => 'SeedSkeletonBootstrap',
				'kind' => 'mandatory',
				'version' => '1.0.0',
				'applied_at' => '2026-05-09 10:00:00',
			],
		];

		[$seed] = $this->invokeSeedRunner('attachStatuses', [$seeds, $applied]);

		$this->assertSame('bootstrap_auto_skipped', $seed['status']);
		$this->assertSame('1.0.0', $seed['applied_version']);
		$this->assertSame('1.1.0', $seed['current_version']);
		$this->assertTrue($seed['bootstrap_marker_present']);
		$this->assertStringContainsString('--rerun-bootstrap-seeds', (string) $seed['message']);
	}

	public function testBootstrapOncePendingStatusExplainsMissingMarker(): void
	{
		$seeds = [$this->seedDescriptor([
			'run_policy' => AbstractSeed::RUN_POLICY_BOOTSTRAP_ONCE,
		])];

		[$seed] = $this->invokeSeedRunner('attachStatuses', [$seeds, []]);

		$this->assertSame('pending', $seed['status']);
		$this->assertFalse($seed['bootstrap_marker_present']);
		$this->assertStringContainsString('Bootstrap seed marker missing', (string) $seed['message']);
	}

	public function testBootstrapOnceChangedSeedRunsOnlyWhenExplicitlyRequested(): void
	{
		$seed = $this->seedDescriptor([
			'status' => 'bootstrap_auto_skipped',
			'run_policy' => AbstractSeed::RUN_POLICY_BOOTSTRAP_ONCE,
		]);

		$this->assertFalse($this->invokeSeedRunner('shouldRunSeed', [$seed, false, false]));
		$this->assertTrue($this->invokeSeedRunner('shouldRunSeed', [$seed, false, true]));
	}

	public function testVersionedMandatorySeedStillRunsWhenChanged(): void
	{
		$seed = $this->seedDescriptor([
			'status' => 'changed',
			'run_policy' => AbstractSeed::RUN_POLICY_VERSIONED,
		]);

		$this->assertTrue($this->invokeSeedRunner('shouldRunSeed', [$seed, false, false]));
	}

	public function testLoadSeedDescriptorReadsDefaultAndBootstrapRunPolicies(): void
	{
		$temp_dir = sys_get_temp_dir() . '/radaptor-seed-policy-' . bin2hex(random_bytes(6));

		$this->assertTrue(mkdir($temp_dir, 0o777, true));

		try {
			$versioned_class = 'SeedPolicyDefault' . bin2hex(random_bytes(4));
			$bootstrap_class = 'SeedPolicyBootstrap' . bin2hex(random_bytes(4));
			$versioned_file = $this->writeSeedFile($temp_dir, $versioned_class, false);
			$bootstrap_file = $this->writeSeedFile($temp_dir, $bootstrap_class, true);

			$versioned = $this->invokeSeedRunner('loadSeedDescriptor', ['app', $temp_dir, 'mandatory', $versioned_file]);
			$bootstrap = $this->invokeSeedRunner('loadSeedDescriptor', ['app', $temp_dir, 'mandatory', $bootstrap_file]);

			$this->assertSame(AbstractSeed::RUN_POLICY_VERSIONED, $versioned['run_policy']);
			$this->assertSame(AbstractSeed::RUN_POLICY_BOOTSTRAP_ONCE, $bootstrap['run_policy']);
		} finally {
			foreach (glob($temp_dir . '/*.php') ?: [] as $path) {
				unlink($path);
			}

			rmdir($temp_dir);
		}
	}

	/**
	 * @param array<string, mixed> $overrides
	 * @return array<string, mixed>
	 */
	private function seedDescriptor(array $overrides = []): array
	{
		return [
			'module' => 'app',
			'kind' => 'mandatory',
			'class' => 'SeedSkeletonBootstrap',
			'path' => '/tmp/Seed.SkeletonBootstrap.php',
			'base_path' => '/tmp/app',
			'version' => '1.0.0',
			'run_policy' => AbstractSeed::RUN_POLICY_VERSIONED,
			'description' => '',
			'dependencies' => [],
			'status' => 'pending',
			...$overrides,
		];
	}

	/**
	 * @param list<mixed> $args
	 */
	private function invokeSeedRunner(string $method, array $args): mixed
	{
		$reflection = new ReflectionClass(SeedRunner::class);
		$method_reflection = $reflection->getMethod($method);

		return $method_reflection->invokeArgs(null, $args);
	}

	private function writeSeedFile(string $temp_dir, string $class_name, bool $bootstrap_once): string
	{
		$run_policy_method = $bootstrap_once
			? "\n\tpublic function getRunPolicy(): string\n\t{\n\t\treturn self::RUN_POLICY_BOOTSTRAP_ONCE;\n\t}\n"
			: '';
		$source = <<<PHP
			<?php

			class {$class_name} extends AbstractSeed
			{
				public function getVersion(): string
				{
					return '1.0.0';
				}
			{$run_policy_method}
				public function run(SeedContext \$context): void
				{
				}
			}
			PHP;
		$filename_suffix = str_starts_with($class_name, 'Seed')
			? substr($class_name, 4)
			: $class_name;
		$path = $temp_dir . '/Seed.' . $filename_suffix . '.php';

		file_put_contents($path, $source);

		return $path;
	}
}
