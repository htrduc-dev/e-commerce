<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionFunction;
use ReflectionMethod;
use ReflectionProperty;
use RuntimeException;
use Tests\Support\MySqlGrantPolicy;
use Tests\Support\MySqlReadOnlyPreflightPolicy;
use Tests\Support\MySqlSafety;

require_once __DIR__.'/../Support/mysql-preflight.php';

/** Pure policy tests: no Laravel TestCase, environment, Docker, or database. */
class MySqlGrantParserTest extends TestCase
{
    private const SCOPE = '`ecommerce\_test`.*';

    private const TEST_ACCOUNT = 'ecommerce_tester@%';

    private const MIGRATION_ACCOUNT = 'ecommerce_migrator@172.19.0.1';

    private const TEST_PRIVILEGES = ['INSERT', 'SELECT'];

    private const MIGRATION_PRIVILEGES = ['ALTER', 'CREATE', 'INSERT', 'REFERENCES', 'SELECT'];

    private const INVALID_GRANT = 'MySQL safety: Unexpected grant scope, principal, role, or clause.';

    private const INCOMPLETE_PRIVILEGES = 'MySQL safety: Database privileges must match the exact approved profile.';

    private const UNKNOWN_PROFILE = 'MySQL safety: Unknown grant profile.';

    private const PARTIAL_REVOKES_MISMATCH = 'MySQL safety: partial_revokes must match the approved OFF baseline.';

    private const ACCOUNT_MISMATCH = 'MySQL safety: Authenticated account does not match the approved grant profile.';

    private const DUPLICATE_USAGE = 'MySQL safety: Duplicate USAGE grant.';

    private const DUPLICATE_PRIVILEGE = 'MySQL safety: Duplicate database privilege.';

    private const EXCESSIVE_PRIVILEGE = 'MySQL safety: Excessive or unsupported database privilege.';

    private const INVALID_ROW_TYPE = 'MySQL safety: Grant rows must be strings.';

    #[DataProvider('validGrants')]
    public function test_accepts_only_the_exact_profile(array $grants, string $profile, string $account, array $expected): void
    {
        $this->assertSame($expected, MySqlGrantPolicy::validate($grants, $profile, $account, 0));
    }

    public static function validGrants(): array
    {
        return [
            'tester with global usage' => [[self::usage(), self::grant('SELECT, INSERT')], 'test', self::TEST_ACCOUNT, self::TEST_PRIVILEGES],
            'tester reordered privileges and rows' => [[self::grant('INSERT, SELECT'), self::usage()], 'test', self::TEST_ACCOUNT, self::TEST_PRIVILEGES],
            'tester without optional usage' => [[self::grant('SELECT, INSERT')], 'test', self::TEST_ACCOUNT, self::TEST_PRIVILEGES],
            'tester split privileges' => [[self::grant('SELECT'), self::grant('INSERT')], 'test', self::TEST_ACCOUNT, self::TEST_PRIVILEGES],
            'migrator verified server order' => [[self::usage('migration'), self::grant('SELECT, INSERT, CREATE, REFERENCES, ALTER', 'migration')], 'migration', self::MIGRATION_ACCOUNT, self::MIGRATION_PRIVILEGES],
            'migrator sorted order without usage' => [[self::grant('ALTER, CREATE, INSERT, REFERENCES, SELECT', 'migration')], 'migration', self::MIGRATION_ACCOUNT, self::MIGRATION_PRIVILEGES],
            'migrator split privileges' => [[self::grant('CREATE, ALTER', 'migration'), self::grant('SELECT, INSERT, REFERENCES', 'migration')], 'migration', self::MIGRATION_ACCOUNT, self::MIGRATION_PRIVILEGES],
        ];
    }

    #[DataProvider('invalidGrants')]
    public function test_rejects_unsafe_or_incomplete_grants(array $grants, string $profile, string $account, int $partialRevokes, string $expectedDiagnostic): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/\\A'.preg_quote($expectedDiagnostic, '/').'\\z/');

        MySqlGrantPolicy::validate($grants, $profile, $account, $partialRevokes);
    }

    public static function invalidGrants(): array
    {
        $validTest = self::grant('SELECT, INSERT');
        $validMigration = self::grant('SELECT, INSERT, CREATE, REFERENCES, ALTER', 'migration');
        $cases = [
            'no grant rows' => [[], 'test', self::TEST_ACCOUNT, 0, self::INCOMPLETE_PRIVILEGES],
            'only usage' => [[self::usage()], 'test', self::TEST_ACCOUNT, 0, self::INCOMPLETE_PRIVILEGES],
            'missing insert' => [[self::grant('SELECT')], 'test', self::TEST_ACCOUNT, 0, self::INCOMPLETE_PRIVILEGES],
            'missing select' => [[self::grant('INSERT')], 'test', self::TEST_ACCOUNT, 0, self::INCOMPLETE_PRIVILEGES],
            'unknown profile' => [[$validTest], 'administrator', self::TEST_ACCOUNT, 0, self::UNKNOWN_PROFILE],
            'changed partial revoke policy' => [[$validTest], 'test', self::TEST_ACCOUNT, 1, self::PARTIAL_REVOKES_MISMATCH],
            'invalid partial revoke value' => [[$validTest], 'test', self::TEST_ACCOUNT, -1, self::PARTIAL_REVOKES_MISMATCH],
            'tester wrong host' => [[$validTest], 'test', 'ecommerce_tester@localhost', 0, self::ACCOUNT_MISMATCH],
            'migrator authenticated as localhost' => [[$validMigration], 'migration', 'ecommerce_migrator@localhost', 0, self::ACCOUNT_MISMATCH],
            'migrator changed host' => [[$validMigration], 'migration', 'ecommerce_migrator@172.19.0.2', 0, self::ACCOUNT_MISMATCH],
            'migrator wildcard host' => [[$validMigration], 'migration', 'ecommerce_migrator@%', 0, self::ACCOUNT_MISMATCH],
            'profile account mismatch' => [[$validMigration], 'test', self::MIGRATION_ACCOUNT, 0, self::ACCOUNT_MISMATCH],
            'empty authenticated account' => [[$validTest], 'test', '', 0, self::ACCOUNT_MISMATCH],
            'wrong grant recipient' => [[str_replace('ecommerce_tester', 'other_user', $validTest)], 'test', self::TEST_ACCOUNT, 0, self::INVALID_GRANT],
            'migrator grant wrong recipient host' => [[str_replace('172.19.0.1', '%', $validMigration)], 'migration', self::MIGRATION_ACCOUNT, 0, self::INVALID_GRANT],
            'migrator grant recipient localhost' => [[str_replace('172.19.0.1', 'localhost', $validMigration)], 'migration', self::MIGRATION_ACCOUNT, 0, self::INVALID_GRANT],
            'tester grant recipient localhost' => [[str_replace('`%`', '`localhost`', $validTest)], 'test', self::TEST_ACCOUNT, 0, self::INVALID_GRANT],
            'usage wrong recipient' => [[$validTest, str_replace('ecommerce_tester', 'other_user', self::usage())], 'test', self::TEST_ACCOUNT, 0, self::INVALID_GRANT],
            'duplicate usage' => [[self::usage(), self::usage(), $validTest], 'test', self::TEST_ACCOUNT, 0, self::DUPLICATE_USAGE],
            'usage with grant option' => [[self::usage().' WITH GRANT OPTION', $validTest], 'test', self::TEST_ACCOUNT, 0, self::INVALID_GRANT],
            'database grant option' => [[$validTest.' WITH GRANT OPTION'], 'test', self::TEST_ACCOUNT, 0, self::INVALID_GRANT],
            'actual column privilege syntax' => [['GRANT SELECT (`price`), INSERT ON '.self::SCOPE.' TO `ecommerce_tester`@`%`'], 'test', self::TEST_ACCOUNT, 0, self::INVALID_GRANT],
            'all privileges' => [[self::grant('ALL PRIVILEGES')], 'test', self::TEST_ACCOUNT, 0, self::INVALID_GRANT],
            'global select' => [[$validTest, 'GRANT SELECT ON *.* TO `ecommerce_tester`@`%`'], 'test', self::TEST_ACCOUNT, 0, self::INVALID_GRANT],
            'global dynamic privilege' => [[$validTest, 'GRANT SYSTEM_USER ON *.* TO `ecommerce_tester`@`%`'], 'test', self::TEST_ACCOUNT, 0, self::INVALID_GRANT],
            'assigned role' => [[$validTest, 'GRANT `test_role`@`%` TO `ecommerce_tester`@`%`'], 'test', self::TEST_ACCOUNT, 0, self::INVALID_GRANT],
            'role with admin option' => [[$validTest, 'GRANT `test_role`@`%` TO `ecommerce_tester`@`%` WITH ADMIN OPTION'], 'test', self::TEST_ACCOUNT, 0, self::INVALID_GRANT],
            'partial revoke statement' => [[$validTest, 'REVOKE SELECT ON `other_database`.* FROM `ecommerce_tester`@`%`'], 'test', self::TEST_ACCOUNT, 0, self::INVALID_GRANT],
            'proxy grant' => [[$validTest, 'GRANT PROXY ON `other_user`@`%` TO `ecommerce_tester`@`%`'], 'test', self::TEST_ACCOUNT, 0, self::INVALID_GRANT],
            'duplicate privilege in row' => [[self::grant('SELECT, INSERT, SELECT')], 'test', self::TEST_ACCOUNT, 0, self::DUPLICATE_PRIVILEGE],
            'duplicate privilege across rows' => [[$validTest, self::grant('SELECT')], 'test', self::TEST_ACCOUNT, 0, self::DUPLICATE_PRIVILEGE],
            'schema usage' => [[$validTest, self::grant('USAGE')], 'test', self::TEST_ACCOUNT, 0, self::EXCESSIVE_PRIVILEGE],
            'lowercase statement' => [[strtolower($validTest)], 'test', self::TEST_ACCOUNT, 0, self::INVALID_GRANT],
            'mixed-case tester privileges' => [[self::grant('SELECT, Insert')], 'test', self::TEST_ACCOUNT, 0, self::INVALID_GRANT],
            'mixed-case migrator privileges' => [[self::grant('SELECT, INSERT, Create, REFERENCES, ALTER', 'migration')], 'migration', self::MIGRATION_ACCOUNT, 0, self::INVALID_GRANT],
            'leading tester whitespace' => [[' '.$validTest], 'test', self::TEST_ACCOUNT, 0, self::INVALID_GRANT],
            'leading migrator whitespace' => [["\t".$validMigration], 'migration', self::MIGRATION_ACCOUNT, 0, self::INVALID_GRANT],
            'trailing newline' => [[$validTest."\n"], 'test', self::TEST_ACCOUNT, 0, self::INVALID_GRANT],
            'additional statement' => [[$validTest.'; SELECT 1'], 'test', self::TEST_ACCOUNT, 0, self::INVALID_GRANT],
            'SQL comment' => [[$validTest.' /* unsupported */'], 'test', self::TEST_ACCOUNT, 0, self::INVALID_GRANT],
            'null byte' => [[$validTest."\0"], 'test', self::TEST_ACCOUNT, 0, self::INVALID_GRANT],
            'non-string grant row' => [[$validTest, null], 'test', self::TEST_ACCOUNT, 0, self::INVALID_ROW_TYPE],
        ];
        foreach ([
            'unescaped underscore' => '`ecommerce_test`.*',
            'different database' => '`other_database`.*',
            'database prefix wildcard' => '`%ecommerce\_test`.*',
            'database suffix wildcard' => '`ecommerce\_test%`.*',
            'different underscore wildcard' => '`ecommerce_test_`.*',
            'escaped percent' => '`ecommerce\%test`.*',
            'double escape' => '`ecommerce\\\\_test`.*',
            'unexpected escape' => '`ecommerce\_tes\t`.*',
            'table-level grant' => '`ecommerce\_test`.`products`',
            'global wildcard' => '*.*',
            'unquoted database' => 'ecommerce\_test.*',
        ] as $label => $scope) {
            $cases[$label] = [[self::grant('SELECT, INSERT', 'test', $scope)], 'test', self::TEST_ACCOUNT, 0, self::INVALID_GRANT];
        }
        foreach (['UPDATE', 'DELETE', 'DROP', 'ALTER', 'CREATE', 'REFERENCES', 'INDEX', 'EXECUTE'] as $privilege) {
            $cases['tester excessive '.$privilege] = [[self::grant('SELECT, INSERT, '.$privilege)], 'test', self::TEST_ACCOUNT, 0, self::EXCESSIVE_PRIVILEGE];
        }
        foreach (self::MIGRATION_PRIVILEGES as $privilege) {
            $remaining = array_values(array_diff(self::MIGRATION_PRIVILEGES, [$privilege]));
            $cases['migrator missing '.$privilege] = [[self::grant(implode(', ', $remaining), 'migration')], 'migration', self::MIGRATION_ACCOUNT, 0, self::INCOMPLETE_PRIVILEGES];
        }
        foreach (['DROP', 'DELETE', 'UPDATE', 'INDEX', 'EXECUTE'] as $privilege) {
            $cases['migrator excessive '.$privilege] = [[self::grant('SELECT, INSERT, CREATE, REFERENCES, ALTER, '.$privilege, 'migration')], 'migration', self::MIGRATION_ACCOUNT, 0, self::EXCESSIVE_PRIVILEGE];
        }
        foreach (['CREATE TEMPORARY TABLES', 'LOCK TABLES', 'CREATE VIEW'] as $privilege) {
            $cases['tester multi-word '.$privilege] = [[self::grant('SELECT, INSERT, '.$privilege)], 'test', self::TEST_ACCOUNT, 0, self::INVALID_GRANT];
            $cases['migrator multi-word '.$privilege] = [[self::grant('SELECT, INSERT, CREATE, REFERENCES, ALTER, '.$privilege, 'migration')], 'migration', self::MIGRATION_ACCOUNT, 0, self::INVALID_GRANT];
        }
        foreach (['PROCEDURE', 'FUNCTION'] as $routineType) {
            $cases['tester routine-level '.$routineType] = [[$validTest, 'GRANT EXECUTE ON '.$routineType.' `ecommerce_test`.`test_routine` TO `ecommerce_tester`@`%`'], 'test', self::TEST_ACCOUNT, 0, self::INVALID_GRANT];
            $cases['migrator routine-level '.$routineType] = [[$validMigration, 'GRANT EXECUTE ON '.$routineType.' `ecommerce_test`.`test_routine` TO `ecommerce_migrator`@`172.19.0.1`'], 'migration', self::MIGRATION_ACCOUNT, 0, self::INVALID_GRANT];
        }
        $cases['migrator all privileges'] = [[self::grant('ALL PRIVILEGES', 'migration')], 'migration', self::MIGRATION_ACCOUNT, 0, self::INVALID_GRANT];
        $cases['migrator unescaped scope'] = [[self::grant('SELECT, INSERT, CREATE, REFERENCES, ALTER', 'migration', '`ecommerce_test`.*')], 'migration', self::MIGRATION_ACCOUNT, 0, self::INVALID_GRANT];
        $cases['migrator assigned role'] = [[$validMigration, 'GRANT `migration_role`@`%` TO `ecommerce_migrator`@`172.19.0.1`'], 'migration', self::MIGRATION_ACCOUNT, 0, self::INVALID_GRANT];
        $cases['migrator role with admin option'] = [[$validMigration, 'GRANT `migration_role`@`%` TO `ecommerce_migrator`@`172.19.0.1` WITH ADMIN OPTION'], 'migration', self::MIGRATION_ACCOUNT, 0, self::INVALID_GRANT];
        $cases['migrator table-level grant'] = [[$validMigration, self::grant('SELECT', 'migration', '`ecommerce\_test`.`products`')], 'migration', self::MIGRATION_ACCOUNT, 0, self::INVALID_GRANT];

        return $cases;
    }

    public function test_rejection_diagnostics_do_not_echo_untrusted_grant_text(): void
    {
        $sentinel = 'synthetic-private-marker';
        try {
            MySqlGrantPolicy::validate([self::grant('SELECT, INSERT').' '.$sentinel], 'test', self::TEST_ACCOUNT, 0);
            $this->fail('Invalid grant must be rejected.');
        } catch (RuntimeException $exception) {
            $this->assertSame(self::INVALID_GRANT, $exception->getMessage());
            $this->assertStringNotContainsString($sentinel, $exception->getMessage());
        }
    }

    #[DataProvider('readOnlyIdentities')]
    public function test_read_only_policy_accepts_each_profile_without_database_access(array $identity, string $profile): void
    {
        MySqlReadOnlyPreflightPolicy::request($profile, '1', '', '', MySqlReadOnlyPreflightPolicy::SERVER_UUID, '172.19.0.1', false);
        MySqlReadOnlyPreflightPolicy::identity($identity, $profile, MySqlReadOnlyPreflightPolicy::SERVER_UUID, '172.19.0.1');
        MySqlReadOnlyPreflightPolicy::emptySchema([]);
        $this->addToAssertionCount(3);
    }

    public static function readOnlyIdentities(): array
    {
        $cases = [];
        foreach (['test', 'migration'] as $profile) {
            $identity = self::readOnlyIdentity($profile);
            $cases[$profile.' native numeric flags'] = [$identity, $profile];
            foreach (['partial_revokes', 'skip_name_resolve', 'activate_all_roles'] as $key) {
                $identity[$key] = (string) $identity[$key];
            }
            $cases[$profile.' string numeric flags'] = [$identity, $profile];
        }

        return $cases;
    }

    #[DataProvider('invalidReadOnlyRequests')]
    public function test_read_only_request_rejects_other_gates_and_unapproved_baselines(array $request, string $expectedDiagnostic): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/\\A'.preg_quote($expectedDiagnostic, '/').'\\z/');

        MySqlReadOnlyPreflightPolicy::request(...$request);
    }

    public static function invalidReadOnlyRequests(): array
    {
        $optIn = 'MySQL safety: Read-only preflight requires its own explicit opt-in.';
        $gates = 'MySQL safety: Read-only preflight cannot share initialization, migration, or test opt-ins.';
        $baseline = 'MySQL safety: Read-only baseline requires explicit approved UUID and source host.';
        $cases = [];
        foreach (['test', 'migration'] as $profile) {
            $request = [$profile, '1', '', '', MySqlReadOnlyPreflightPolicy::SERVER_UUID, '172.19.0.1', false];
            foreach ([
                'missing opt-in' => [1, '', $optIn],
                'false opt-in' => [1, '0', $optIn],
                'noncanonical opt-in' => [1, 'true', $optIn],
                'initialize opt-in' => [2, 'initialize', $gates],
                'preview opt-in' => [2, 'preview', $gates],
                'apply opt-in' => [2, 'apply', $gates],
                'integration-test opt-in' => [3, '1', $gates],
                'missing UUID' => [4, '', $baseline],
                'changed UUID' => [4, '00000000-0000-0000-0000-000000000000', $baseline],
                'missing source host' => [5, '', $baseline],
                'changed source host' => [5, '172.19.0.2', $baseline],
                'hostname instead of source IP' => [5, 'localhost', $baseline],
                'session configuration enabled' => [6, true, 'MySQL safety: Read-only preflight cannot configure database sessions.'],
            ] as $label => [$index, $value, $diagnostic]) {
                $invalid = $request;
                $invalid[$index] = $value;
                $cases[$profile.' '.$label] = [$invalid, $diagnostic];
            }
        }
        $cases['unsupported profile'] = [['root', '1', '', '', MySqlReadOnlyPreflightPolicy::SERVER_UUID, '172.19.0.1', false], self::UNKNOWN_PROFILE];

        return $cases;
    }

    #[DataProvider('invalidReadOnlyIdentities')]
    public function test_read_only_identity_rejects_source_account_server_and_role_drift(array $identity, string $profile, string $expectedDiagnostic): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/\\A'.preg_quote($expectedDiagnostic, '/').'\\z/');

        MySqlReadOnlyPreflightPolicy::identity($identity, $profile, MySqlReadOnlyPreflightPolicy::SERVER_UUID, '172.19.0.1');
    }

    public static function invalidReadOnlyIdentities(): array
    {
        $server = 'MySQL safety: Read-only database/server identity mismatch.';
        $account = 'MySQL safety: Read-only authenticated account mismatch.';
        $source = 'MySQL safety: Read-only connection source identity mismatch.';
        $roles = 'MySQL safety: Read-only role settings mismatch.';
        $settings = 'MySQL safety: Read-only grant/host settings mismatch.';
        $cases = [];
        foreach (['test', 'migration'] as $profile) {
            $identity = self::readOnlyIdentity($profile);
            foreach ([
                'different database' => ['db', 'other_database', $server],
                'no selected database' => ['db', null, $server],
                'different version' => ['version', '8.4.12', $server],
                'different UUID' => ['uuid', '00000000-0000-0000-0000-000000000000', $server],
                'different account' => ['account', 'other_user@%', $account],
                'localhost account' => ['account', explode('@', $identity['account'], 2)[0].'@localhost', $account],
                'different client source' => ['client_identity', explode('@', $identity['client_identity'], 2)[0].'@172.19.0.2', $source],
                'source IP prefix collision' => ['client_identity', explode('@', $identity['client_identity'], 2)[0].'@172.19.0.10', $source],
                'different presented username' => ['client_identity', 'other_user@172.19.0.1', $source],
                'localhost source' => ['client_identity', explode('@', $identity['client_identity'], 2)[0].'@localhost', $source],
                'active role' => ['roles', '`extra_role`@`%`', $roles],
                'mandatory role' => ['mandatory_roles', 'extra_role', $roles],
                'automatic roles enabled' => ['activate_all_roles', 1, $roles],
                'malformed automatic roles' => ['activate_all_roles', 'invalid', $roles],
                'partial revokes enabled' => ['partial_revokes', 1, $settings],
                'malformed partial revokes' => ['partial_revokes', '0invalid', $settings],
                'name resolution enabled' => ['skip_name_resolve', 0, $settings],
                'malformed name resolution' => ['skip_name_resolve', 'true', $settings],
            ] as $label => [$key, $value, $diagnostic]) {
                $invalid = $identity;
                $invalid[$key] = $value;
                $cases[$profile.' '.$label] = [$invalid, $profile, $diagnostic];
            }
            foreach (array_keys($identity) as $key) {
                $invalid = $identity;
                unset($invalid[$key]);
                $cases[$profile.' missing '.$key] = [$invalid, $profile, 'MySQL safety: Incomplete read-only identity result.'];
            }
        }

        return $cases;
    }

    #[DataProvider('nonemptyOrMalformedSchemas')]
    public function test_read_only_schema_requires_zero_tables_views_and_no_repository(array $tables, string $expectedDiagnostic): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/\\A'.preg_quote($expectedDiagnostic, '/').'\\z/');

        MySqlReadOnlyPreflightPolicy::emptySchema($tables);
    }

    public static function nonemptyOrMalformedSchemas(): array
    {
        $repository = 'MySQL safety: Read-only pre-initialization requires the migrations repository to be absent.';
        $nonempty = 'MySQL safety: Read-only pre-initialization requires zero tables and views.';
        $malformed = 'MySQL safety: Malformed read-only schema result.';

        return [
            'repository table exists' => [[['TABLE_NAME' => 'migrations', 'TABLE_TYPE' => 'BASE TABLE']], $repository],
            'repository name is a view' => [[['TABLE_NAME' => 'migrations', 'TABLE_TYPE' => 'VIEW']], $repository],
            'business table exists' => [[['TABLE_NAME' => 'products', 'TABLE_TYPE' => 'BASE TABLE']], $nonempty],
            'view exists' => [[['TABLE_NAME' => 'product_view', 'TABLE_TYPE' => 'VIEW']], $nonempty],
            'missing metadata fields' => [[[]], $malformed],
            'null metadata field' => [[['TABLE_NAME' => 'migrations', 'TABLE_TYPE' => null]], $malformed],
            'non-string table name' => [[['TABLE_NAME' => 123, 'TABLE_TYPE' => 'BASE TABLE']], $malformed],
            'non-row metadata' => [[null], $malformed],
            'associative result' => [['products' => ['TABLE_NAME' => 'products', 'TABLE_TYPE' => 'BASE TABLE']], $malformed],
        ];
    }

    #[DataProvider('connectionContexts')]
    public function test_lazy_factory_captures_its_original_security_context(string $profile, bool $configureSession, bool $readOnly): void
    {
        $config = ['unit_marker' => 'original'];
        $factory = (new ReflectionMethod(MySqlSafety::class, 'pdoFactory'))->invoke(null, $config, $profile, $configureSession, $readOnly);
        $config['unit_marker'] = 'changed';
        $this->withHarnessState([
            'profile' => $profile === 'test' ? 'migration' : 'test',
            'configureSession' => ! $configureSession,
            'readOnlyPreflight' => ! $readOnly,
        ], function () use ($factory, $profile, $configureSession, $readOnly): void {
            $captured = (new ReflectionFunction($factory))->getStaticVariables();
            $this->assertSame($profile, $captured['profile']);
            $this->assertSame($configureSession, $captured['configureSession']);
            $this->assertSame($readOnly, $captured['readOnlyPreflight']);
            $this->assertSame(['unit_marker' => 'original'], $captured['config']);
        });
    }

    public static function connectionContexts(): array
    {
        return [
            'read-only tester' => ['test', false, true],
            'read-only migrator' => ['migration', false, true],
            'legacy read-only tester' => ['test', false, false],
            'integration tester' => ['test', true, false],
            'forward migrator' => ['migration', true, false],
        ];
    }

    public function test_pending_read_only_factory_cannot_be_downgraded_by_static_state(): void
    {
        $factory = (new ReflectionMethod(MySqlSafety::class, 'pdoFactory'))->invoke(null, [], 'migration', false, true);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/\\AMySQL safety: Read-only preflight requires its own explicit opt-in\\.\\z/');
        $this->withHarnessState(['profile' => 'test', 'configureSession' => true, 'readOnlyPreflight' => false], function () use ($factory): void {
            $this->withPublicEnvironment(['ECOMMERCE_MYSQL_PREFLIGHT_OPT_IN' => ''], static function () use ($factory): void {
                // Expected failure is the captured read-only gate, before the
                // deliberately invalid APP_ENV, credentials, Docker, or PDO.
                $factory();
            });
        });
    }

    public function test_pending_write_factory_cannot_use_a_later_read_only_opt_in(): void
    {
        $factory = (new ReflectionMethod(MySqlSafety::class, 'pdoFactory'))->invoke(null, [], 'migration', true, false);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/\\AMySQL safety: Preflight opt-in cannot authorize initialization, migration, or integration tests\\.\\z/');
        $this->withHarnessState(['profile' => 'test', 'configureSession' => false, 'readOnlyPreflight' => true], function () use ($factory): void {
            $this->withPublicEnvironment([], static function () use ($factory): void {
                $factory();
            });
        });
    }

    #[DataProvider('rejectedApplicationRequests')]
    public function test_failed_application_gates_leave_shared_context_unchanged(string $profile, bool $configureSession, bool $readOnly, array $environment, string $expectedDiagnostic): void
    {
        $this->withHarnessState(['profile' => 'test', 'configureSession' => false, 'readOnlyPreflight' => false], function () use ($profile, $configureSession, $readOnly, $environment, $expectedDiagnostic): void {
            $before = self::harnessState();
            $this->withPublicEnvironment($environment, function () use ($profile, $configureSession, $readOnly, $expectedDiagnostic): void {
                try {
                    MySqlSafety::application($profile, $configureSession, $readOnly);
                    $this->fail('Invalid request must fail before application bootstrap.');
                } catch (RuntimeException $exception) {
                    $this->assertSame($expectedDiagnostic, $exception->getMessage());
                }
            });
            $this->assertSame($before, self::harnessState());
        });
    }

    public static function rejectedApplicationRequests(): array
    {
        return [
            'read-only session modification' => ['migration', true, true, [], 'MySQL safety: Read-only preflight cannot configure database sessions.'],
            'missing read-only approval' => ['migration', false, true, ['ECOMMERCE_MYSQL_PREFLIGHT_OPT_IN' => ''], 'MySQL safety: Read-only preflight requires its own explicit opt-in.'],
            'conflicting preview approval' => ['migration', false, true, ['ECOMMERCE_MYSQL_MIGRATION_OPT_IN' => 'preview'], 'MySQL safety: Read-only preflight cannot share initialization, migration, or test opt-ins.'],
            'unsupported profile' => ['root', false, true, [], 'MySQL safety: Unknown credential profile.'],
            'write operation with read-only approval' => ['test', true, false, [], 'MySQL safety: Preflight opt-in cannot authorize initialization, migration, or integration tests.'],
            'missing migration approval' => ['migration', true, false, ['ECOMMERCE_MYSQL_PREFLIGHT_OPT_IN' => ''], 'MySQL safety: Migration profile requires an explicit initialize/preview/apply opt-in.'],
            'environment failure after valid request' => ['migration', false, true, [], 'MySQL safety: Unexpected APP_ENV.'],
        ];
    }

    private static function harnessState(): array
    {
        $state = [];
        foreach (['profile', 'configureSession', 'readOnlyPreflight', 'lock'] as $name) {
            $state[$name] = (new ReflectionProperty(MySqlSafety::class, $name))->getValue();
        }

        return $state;
    }

    private function withHarnessState(array $overrides, callable $callback): void
    {
        $before = self::harnessState();
        try {
            foreach ($overrides as $name => $value) {
                (new ReflectionProperty(MySqlSafety::class, $name))->setValue(null, $value);
            }
            $callback();
        } finally {
            foreach ($before as $name => $value) {
                (new ReflectionProperty(MySqlSafety::class, $name))->setValue(null, $value);
            }
        }
    }

    private function withPublicEnvironment(array $overrides, callable $callback): void
    {
        $values = array_replace([
            // Independent fallback barrier: even a mode-gate regression must fail
            // before reading passwords, inspecting Docker, or opening a PDO.
            'APP_ENV' => 'unit-no-connect',
            'ECOMMERCE_MYSQL_ALLOW_DB' => '1',
            'ECOMMERCE_MYSQL_PREFLIGHT_OPT_IN' => '1',
            'ECOMMERCE_MYSQL_MIGRATION_OPT_IN' => '',
            'ECOMMERCE_MYSQL_PRICE_TEST_OPT_IN' => '',
            'ECOMMERCE_MYSQL_SERVER_UUID' => MySqlReadOnlyPreflightPolicy::SERVER_UUID,
            'ECOMMERCE_MYSQL_EXPECTED_SOURCE_HOST' => '172.19.0.1',
        ], $overrides);
        $before = [];
        try {
            foreach ($values as $name => $value) {
                $before[$name] = getenv($name);
                putenv($name.'='.$value);
            }
            $callback();
        } finally {
            foreach ($before as $name => $value) {
                putenv($value === false ? $name : $name.'='.$value);
            }
        }
    }

    private static function readOnlyIdentity(string $profile): array
    {
        $account = $profile === 'test' ? self::TEST_ACCOUNT : self::MIGRATION_ACCOUNT;

        return [
            'db' => 'ecommerce_test',
            'version' => '8.4.13',
            'uuid' => '46141f1d-e257-11f1-ab68-66627604040d',
            'client_identity' => explode('@', $account, 2)[0].'@172.19.0.1',
            'account' => $account,
            'roles' => 'NONE',
            'partial_revokes' => 0,
            'skip_name_resolve' => 1,
            'mandatory_roles' => '',
            'activate_all_roles' => 0,
        ];
    }

    private static function grant(string $privileges, string $profile = 'test', string $scope = self::SCOPE): string
    {
        $principal = $profile === 'test' ? '`ecommerce_tester`@`%`' : '`ecommerce_migrator`@`172.19.0.1`';

        return 'GRANT '.$privileges.' ON '.$scope.' TO '.$principal;
    }

    private static function usage(string $profile = 'test'): string
    {
        $principal = $profile === 'test' ? '`ecommerce_tester`@`%`' : '`ecommerce_migrator`@`172.19.0.1`';

        return 'GRANT USAGE ON *.* TO '.$principal;
    }
}
