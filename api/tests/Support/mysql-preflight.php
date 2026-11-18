<?php

namespace Tests\Support;

use Closure;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Connectors\MySqlConnector;
use Illuminate\Database\Console\Migrations\InstallCommand;
use Illuminate\Database\Migrations\DatabaseMigrationRepository;
use Illuminate\Database\MySqlConnection;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PDO;
use ReflectionMethod;
use RuntimeException;
use Tests\MySql\MySqlTestCase;
use Throwable;

require_once dirname(__DIR__, 2).'/vendor/autoload.php';

/** Shared fail-closed guard: importing this file never opens a connection. */
final class GuardedMySqlConnection extends MySqlConnection {}

/** Reuses only session configuration; never calls connect()/creates another PDO. */
final class GuardedMySqlSession extends MySqlConnector
{
    public function apply(PDO $pdo, array $config): void
    {
        parent::configureConnection($pdo, $config);
    }

    public function expectedMode(PDO $pdo, array $config): string
    {
        return (string) parent::getSqlMode($pdo, $config);
    }

    public static function verifyApi(): void
    {
        foreach (['configureConnection', 'getSqlMode'] as $name) {
            $method = new ReflectionMethod(MySqlConnector::class, $name);
            if (! $method->isProtected() || $method->isFinal() || $method->getNumberOfParameters() !== 2
                || $method->getDeclaringClass()->getName() !== MySqlConnector::class
                || (string) $method->getParameters()[0]->getType() !== PDO::class
                || (string) $method->getParameters()[1]->getType() !== 'array') {
                throw new RuntimeException('MySQL safety: unsupported Laravel MySqlConnector session API; BLOCKED.');
            }
        }
    }
}

/** Pure SHOW GRANTS policy: no environment, Laravel bootstrap, Docker, or PDO. */
final class MySqlGrantPolicy
{
    private const PROFILES = [
        'test' => ['account' => 'ecommerce_tester@%', 'privileges' => ['INSERT', 'SELECT']],
        'migration' => ['account' => 'ecommerce_migrator@172.19.0.1', 'privileges' => ['ALTER', 'CREATE', 'INSERT', 'REFERENCES', 'SELECT']],
    ];

    public static function account(string $profile): string
    {
        self::require(isset(self::PROFILES[$profile]), 'Unknown grant profile.');

        return self::PROFILES[$profile]['account'];
    }

    public static function validate(array $grants, string $profile, string $account, int $partialRevokes): array
    {
        self::require($account === self::account($profile), 'Authenticated account does not match the approved grant profile.');
        self::require($partialRevokes === 0, 'partial_revokes must match the approved OFF baseline.');
        $policy = self::PROFILES[$profile];
        [$user, $host] = explode('@', $account, 2);
        $principal = '`'.$user.'`@`'.$host.'`';
        // With partial_revokes=0, the unescaped underscore is a database wildcard.
        // Compare the raw identifier exactly; never strip arbitrary backslashes.
        $scope = '`ecommerce\_test`.*';
        $pattern = '~\AGRANT ([A-Z]+(?:, [A-Z]+)*) ON '.preg_quote($scope, '~').' TO '.preg_quote($principal, '~').'\z~';
        $privileges = [];
        $usageSeen = false;
        foreach ($grants as $grant) {
            self::require(is_string($grant), 'Grant rows must be strings.');
            if ($grant === 'GRANT USAGE ON *.* TO '.$principal) {
                self::require(! $usageSeen, 'Duplicate USAGE grant.');
                $usageSeen = true;

                continue;
            }
            self::require(preg_match($pattern, $grant, $match) === 1, 'Unexpected grant scope, principal, role, or clause.');
            foreach (explode(', ', $match[1]) as $privilege) {
                self::require(in_array($privilege, $policy['privileges'], true), 'Excessive or unsupported database privilege.');
                self::require(! isset($privileges[$privilege]), 'Duplicate database privilege.');
                $privileges[$privilege] = true;
            }
        }
        $actual = array_keys($privileges);
        sort($actual);
        self::require($actual === $policy['privileges'], 'Database privileges must match the exact approved profile.');

        return $actual;
    }

    private static function require(bool $condition, string $message): void
    {
        if (! $condition) {
            // Never include raw grant rows or caller-supplied values in errors.
            throw new RuntimeException('MySQL safety: '.$message);
        }
    }
}

/** Pure validation for the pre-initialization, read-only inventory operation. */
final class MySqlReadOnlyPreflightPolicy
{
    public const SERVER_UUID = '46141f1d-e257-11f1-ab68-66627604040d';

    public const SOURCE_HOST = '172.19.0.1';

    public const IDENTITY_SQL = 'SELECT DATABASE() AS db, VERSION() AS version, @@server_uuid AS uuid, USER() AS client_identity, CURRENT_USER() AS account, CURRENT_ROLE() AS roles, @@GLOBAL.partial_revokes AS partial_revokes, @@GLOBAL.skip_name_resolve AS skip_name_resolve, @@GLOBAL.mandatory_roles AS mandatory_roles, @@GLOBAL.activate_all_roles_on_login AS activate_all_roles';

    public const TABLES_SQL = 'SELECT TABLE_NAME, TABLE_TYPE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() ORDER BY TABLE_NAME';

    public static function request(string $profile, string $optIn, string $migrationOptIn, string $testOptIn, string $expectedUuid, string $expectedSourceHost, bool $configureSession): void
    {
        self::baseline($profile, $expectedUuid, $expectedSourceHost);
        self::require($optIn === '1', 'Read-only preflight requires its own explicit opt-in.');
        self::require($migrationOptIn === '' && $testOptIn === '', 'Read-only preflight cannot share initialization, migration, or test opt-ins.');
        self::require(! $configureSession, 'Read-only preflight cannot configure database sessions.');
    }

    public static function identity(array $row, string $profile, string $expectedUuid, string $expectedSourceHost): void
    {
        self::baseline($profile, $expectedUuid, $expectedSourceHost);
        foreach (['db', 'version', 'uuid', 'client_identity', 'account', 'roles', 'partial_revokes', 'skip_name_resolve', 'mandatory_roles', 'activate_all_roles'] as $key) {
            self::require(array_key_exists($key, $row), 'Incomplete read-only identity result.');
        }
        self::require($row['db'] === MySqlSafety::DATABASE && $row['version'] === MySqlSafety::VERSION && $row['uuid'] === $expectedUuid, 'Read-only database/server identity mismatch.');
        $account = MySqlGrantPolicy::account($profile);
        $username = explode('@', $account, 2)[0];
        self::require($row['account'] === $account, 'Read-only authenticated account mismatch.');
        self::require($row['client_identity'] === $username.'@'.$expectedSourceHost, 'Read-only connection source identity mismatch.');
        self::require($row['roles'] === 'NONE' && $row['mandatory_roles'] === ''
            && in_array($row['activate_all_roles'], [0, '0'], true), 'Read-only role settings mismatch.');
        self::require(in_array($row['partial_revokes'], [0, '0'], true)
            && in_array($row['skip_name_resolve'], [1, '1'], true), 'Read-only grant/host settings mismatch.');
    }

    public static function emptySchema(array $tables): void
    {
        self::require(array_is_list($tables), 'Malformed read-only schema result.');
        foreach ($tables as $table) {
            self::require(is_array($table) && isset($table['TABLE_NAME'], $table['TABLE_TYPE'])
                && is_string($table['TABLE_NAME']) && is_string($table['TABLE_TYPE']), 'Malformed read-only schema result.');
            self::require($table['TABLE_NAME'] !== 'migrations', 'Read-only pre-initialization requires the migrations repository to be absent.');
        }
        self::require($tables === [], 'Read-only pre-initialization requires zero tables and views.');
    }

    private static function baseline(string $profile, string $expectedUuid, string $expectedSourceHost): void
    {
        MySqlGrantPolicy::account($profile);
        self::require($expectedUuid === self::SERVER_UUID && $expectedSourceHost === self::SOURCE_HOST, 'Read-only baseline requires explicit approved UUID and source host.');
    }

    private static function require(bool $condition, string $message): void
    {
        if (! $condition) {
            throw new RuntimeException('MySQL safety: '.$message);
        }
    }
}

final class MySqlSafety
{
    public const HOST = '127.0.0.1';

    public const PORT = '3307';

    public const DATABASE = 'ecommerce_test';

    public const VERSION = '8.4.13';

    private static mixed $lock = null;

    private static string $profile = 'test';

    private static bool $configureSession = false;

    private static bool $readOnlyPreflight = false;

    private static ?array $containerCache = null;

    private static ?array $binaryCache = null;

    private static function username(?string $profile = null): string
    {
        return explode('@', MySqlGrantPolicy::account($profile ?? self::$profile), 2)[0];
    }

    private static function password(?string $profile = null): string
    {
        return self::variable(($profile ?? self::$profile) === 'test' ? 'ECOMMERCE_MYSQL_TEST_PASSWORD' : 'ECOMMERCE_MYSQL_MIGRATION_PASSWORD');
    }

    public static function testClass(string $class): void
    {
        self::require(is_subclass_of($class, MySqlTestCase::class), 'Test class '.$class.' must extend MySqlTestCase.');
        $traits = class_uses_recursive($class);
        foreach ([
            RefreshDatabase::class,
            DatabaseMigrations::class,
            DatabaseTruncation::class,
            DatabaseTransactions::class,
        ] as $trait) {
            self::require(! in_array($trait, $traits, true), 'Test class '.$class.' uses forbidden trait '.$trait.'.');
        }
    }

    public static function connection(Application $app, ?string $profile = null): GuardedMySqlConnection
    {
        self::require($app['config']->get('database.default') === 'mysql'
            && array_keys($app['config']->get('database.connections')) === ['mysql'], 'Unexpected effective connections.');
        self::configuration($app['config']->get('database.connections.mysql'), $profile);
        $connection = $app['db']->connection('mysql');
        self::require($connection instanceof GuardedMySqlConnection, 'Guarded resolver was not installed.');
        self::configuration($connection->getConfig(), $profile);

        return $connection;
    }

    public static function noSchemaDumps(): void
    {
        $path = dirname(__DIR__, 2).'/database/schema';
        self::require(! is_link($path), 'Symlink schema directory forbidden.');
        if (! file_exists($path)) {
            return;
        }
        self::require(is_dir($path) && is_readable($path), 'Cannot inspect schema directory.');
        $files = scandir($path);
        self::require($files !== false && array_diff($files, ['.', '..']) === [], 'Schema dumps require independent review; refusing migration.');
    }

    public static function migration(bool $preview): int
    {
        return self::safeOperation($preview ? 'preview' : 'apply', static fn (): array => self::runMigration($preview));
    }

    private static function runMigration(bool $preview): array
    {
        self::require(self::variable('ECOMMERCE_MYSQL_MIGRATION_OPT_IN') === ($preview ? 'preview' : 'apply'), 'Separate B2 approval/opt-in required.');
        self::require(self::variable('ECOMMERCE_MYSQL_PRICE_TEST_OPT_IN') === '', 'Test opt-in forbidden for migrations.');
        self::noSchemaDumps();
        $app = self::application('migration', true);
        $pdo = self::connection($app)->getPdo();
        self::identity($pdo);
        // Laravel migrate --pretend may install the repository if it is missing.
        $repository = $pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'migrations'")->fetchColumn();
        self::require((int) $repository === 1, 'Migration repository missing; separately approve its initialization before preview/apply.');
        $kernel = $app->make(Kernel::class);
        $status = $kernel->call('migrate', ['--database' => 'mysql', '--no-interaction' => true, '--pretend' => $preview]);

        return ['status' => $status, 'output' => $kernel->output()];
    }

    public static function initializeRepository(): int
    {
        return self::safeOperation('initialize', static fn (): array => self::runRepositoryInitialization());
    }

    private static function runRepositoryInitialization(): array
    {
        self::require(self::variable('ECOMMERCE_MYSQL_MIGRATION_OPT_IN') === 'initialize', 'Repository initialization requires separate approval/opt-in.');
        self::require(self::variable('ECOMMERCE_MYSQL_PRICE_TEST_OPT_IN') === '', 'Test opt-in forbidden for repository initialization.');
        self::noSchemaDumps();
        $app = self::application('migration', true);
        $connection = self::connection($app);
        $pdo = $connection->getPdo();
        self::identity($pdo);
        self::require($app['config']->get('database.migrations.table') === 'migrations', 'Unexpected migration repository configuration.');
        $repository = $app['migration.repository'];
        self::require(get_class($repository) === DatabaseMigrationRepository::class
            && $repository->getConnectionResolver() === $app['db'], 'Unexpected Laravel migration repository binding.');
        $repository->setSource('mysql');
        self::require($repository->getConnection() === $connection, 'Repository must use the guarded MySQL connection.');
        $engine = $connection->getConfig('engine');
        self::require($engine === 'InnoDB' || ($engine === null && $pdo->query('SELECT @@SESSION.default_storage_engine')->fetchColumn() === 'InnoDB'), 'Repository requires an InnoDB storage-engine configuration.');
        $tables = $pdo->query('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()')->fetchAll(PDO::FETCH_COLUMN);
        self::require($tables === [], 'Repository initialization requires an empty database; no automatic repair or reset.');
        // Installed Laravel 13 InstallCommand only creates the repository. It does
        // not discover/run application migrations, seed data, or load schema dumps.
        $kernel = $app->make(Kernel::class);
        $commands = $kernel->all();
        self::require(isset($commands['migrate:install']) && get_class($commands['migrate:install']) === InstallCommand::class, 'Unexpected migrate:install command implementation.');
        $status = $kernel->call('migrate:install', ['--database' => 'mysql', '--no-interaction' => true]);
        if ($status !== 0) {
            return ['status' => $status, 'output' => ''];
        }
        self::identity($pdo);
        $tables = $pdo->query('SELECT TABLE_NAME, TABLE_TYPE, ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()')->fetchAll(PDO::FETCH_ASSOC);
        self::require(count($tables) === 1 && $tables[0]['TABLE_NAME'] === 'migrations'
            && $tables[0]['TABLE_TYPE'] === 'BASE TABLE' && $tables[0]['ENGINE'] === 'InnoDB', 'Unexpected schema after repository initialization; no automatic cleanup.');
        $columns = $pdo->query("SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, EXTRA FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'migrations' ORDER BY ORDINAL_POSITION")->fetchAll(PDO::FETCH_ASSOC);
        self::require($columns === [
            ['COLUMN_NAME' => 'id', 'COLUMN_TYPE' => 'int unsigned', 'IS_NULLABLE' => 'NO', 'EXTRA' => 'auto_increment'],
            ['COLUMN_NAME' => 'migration', 'COLUMN_TYPE' => 'varchar(255)', 'IS_NULLABLE' => 'NO', 'EXTRA' => ''],
            ['COLUMN_NAME' => 'batch', 'COLUMN_TYPE' => 'int', 'IS_NULLABLE' => 'NO', 'EXTRA' => ''],
        ], 'Repository columns differ from the reviewed Laravel definition.');
        $primary = $pdo->query("SELECT COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'migrations' AND CONSTRAINT_NAME = 'PRIMARY' ORDER BY ORDINAL_POSITION")->fetchAll(PDO::FETCH_COLUMN);
        self::require($primary === ['id'] && (int) $pdo->query('SELECT EXISTS(SELECT 1 FROM `migrations` LIMIT 1)')->fetchColumn() === 0, 'Repository primary key/records differ from initialization postconditions.');
        $output = $kernel->output();
        $connection->disconnect();

        return ['status' => $status, 'output' => $output];
    }

    public static function preflight(array $arguments): int
    {
        return self::safeOperation('preflight', static function () use ($arguments): array {
            $mode = $arguments[1] ?? '';
            if ($mode === '--read-only') {
                self::require(count($arguments) === 3, 'Read-only preflight requires one explicit profile.');
                $profile = match ($arguments[2] ?? '') {
                    '--profile=test' => 'test',
                    '--profile=migration' => 'migration',
                    default => throw new RuntimeException('MySQL safety: unsupported read-only profile.'),
                };

                return self::runReadOnlyPreflight($profile);
            }
            self::require(in_array($mode, ['--environment', '--database', '--schema'], true) && count($arguments) === 2, 'Unsupported preflight arguments.');
            self::environment();
            if ($mode !== '--environment') {
                $app = self::application();
                $pdo = self::connection($app)->getPdo();
                self::identity($pdo);
                if ($mode === '--schema') {
                    self::schema($pdo);
                }
                $app['db']->disconnect('mysql');
            }

            return ['status' => 0, 'output' => 'PASS '.$mode.PHP_EOL];
        });
    }

    private static function runReadOnlyPreflight(string $profile): array
    {
        // Select the profile before environment() so the other password is never
        // needed/exposed. The application disables session configuration entirely.
        $app = self::application($profile, false, true);
        $connection = self::connection($app, $profile);
        try {
            $pdo = $connection->getPdo();
            self::identity($pdo, $profile, true);
            MySqlReadOnlyPreflightPolicy::emptySchema($pdo->query(MySqlReadOnlyPreflightPolicy::TABLES_SQL)->fetchAll(PDO::FETCH_ASSOC));
        } finally {
            $connection->disconnect();
        }

        return ['status' => 0, 'output' => 'PASS --read-only --profile='.$profile.' (identity, source, grants, empty schema, repository absent)'.PHP_EOL];
    }

    private static function readOnlyRequest(string $profile, bool $configureSession): void
    {
        MySqlReadOnlyPreflightPolicy::request($profile, self::variable('ECOMMERCE_MYSQL_PREFLIGHT_OPT_IN'), self::variable('ECOMMERCE_MYSQL_MIGRATION_OPT_IN'), self::variable('ECOMMERCE_MYSQL_PRICE_TEST_OPT_IN'), self::variable('ECOMMERCE_MYSQL_SERVER_UUID'), self::variable('ECOMMERCE_MYSQL_EXPECTED_SOURCE_HOST'), $configureSession);
    }

    private static function safeOperation(string $operation, callable $callback): int
    {
        $bufferLevel = ob_get_level();
        $displayErrors = ini_get('display_errors');
        $logErrors = ini_get('log_errors');
        $status = 1;
        $output = '';

        try {
            self::require(ini_set('display_errors', '0') !== false && ini_set('log_errors', '0') !== false, 'Cannot suppress raw PHP diagnostics.');
            // Discard incidental echo/print output, including buffered diagnostics.
            // Only explicitly returned command output is released on success.
            self::require(ob_start(static fn (string $buffer): string => '') !== false, 'Cannot establish output boundary.');
            $result = $callback();
            self::require(is_array($result) && isset($result['status'], $result['output'])
                && is_int($result['status']) && $result['status'] >= 0 && $result['status'] <= 255
                && is_string($result['output']), 'Invalid operation result.');
            $status = $result['status'];
            $output = $status === 0 ? $result['output'] : '';
        } catch (Throwable) {
            // Do not trust exception class, prefix, message, code, SQL, or trace.
            $status = 1;
            $output = '';
        } finally {
            while (ob_get_level() > $bufferLevel) {
                ob_end_clean();
            }
            if ($displayErrors !== false) {
                ini_set('display_errors', $displayErrors);
            }
            if ($logErrors !== false) {
                ini_set('log_errors', $logErrors);
            }
        }
        if ($status !== 0) {
            fwrite(STDERR, 'BLOCKED: '.$operation.' failed; diagnostic details omitted. No automatic cleanup or retry.'.PHP_EOL);
        } else {
            fwrite(STDOUT, $output);
        }

        return $status;
    }

    private static function require(bool $condition, string $message): void
    {
        if (! $condition) {
            throw new RuntimeException('MySQL safety: '.$message);
        }
    }

    private static function variable(string $name): string
    {
        return (string) (getenv($name) === false ? '' : getenv($name));
    }

    public static function environment(?string $profile = null): void
    {
        $profile ??= self::$profile;
        MySqlGrantPolicy::account($profile);
        self::require(PHP_OS_FAMILY === 'Darwin' && PHP_VERSION_ID >= 80300, 'macOS PHP 8.3+ required.');
        foreach (['pdo_mysql', 'dom', 'mbstring', 'xml', 'xmlwriter'] as $extension) {
            self::require(extension_loaded($extension), 'Missing PHP extension '.$extension.'.');
        }
        foreach (['APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_HOST' => self::HOST, 'DB_PORT' => self::PORT, 'DB_DATABASE' => self::DATABASE] as $key => $value) {
            self::require(self::variable($key) === $value, 'Unexpected '.$key.'.');
        }
        foreach (['DB_USERNAME', 'DB_PASSWORD', 'DB_URL', 'DATABASE_URL', 'DB_SOCKET', 'MYSQL_ATTR_SSL_CA', 'APP_CONFIG_CACHE', 'APP_ROUTES_CACHE', 'APP_ENVIRONMENT_PATH', 'TEST_TOKEN', 'UNIQUE_TEST_TOKEN', 'DOCKER_HOST', 'DOCKER_CONTEXT'] as $key) {
            self::require(self::variable($key) === '', 'Forbidden override '.$key.'.');
        }
        self::require(self::password($profile) !== '', 'Selected profile credential required.');
        self::require(self::variable($profile === 'test' ? 'ECOMMERCE_MYSQL_MIGRATION_PASSWORD' : 'ECOMMERCE_MYSQL_TEST_PASSWORD') === '', 'Do not expose the other credential profile to this process.');
        self::require(! is_file(dirname(__DIR__, 2).'/bootstrap/cache/config.php'), 'Cached configuration forbidden.');
        self::require(! file_exists(__DIR__.'/.mysql-no-env'), 'Reserved empty environment path must not exist.');
        self::require(preg_match('/^[a-f0-9]{64}$/D', self::variable('ECOMMERCE_MYSQL_CONTAINER_ID')) === 1, 'Reviewed full container ID required.');
        self::require(preg_match('/^sha256:[a-f0-9]{64}$/D', self::variable('ECOMMERCE_MYSQL_IMAGE_ID')) === 1, 'Reviewed image ID required.');
        self::require(preg_match('/^[a-f0-9-]{36}$/Di', self::variable('ECOMMERCE_MYSQL_SERVER_UUID')) === 1, 'Reviewed server UUID required.');
        self::lock();
        self::container();
    }

    private static function lock(): void
    {
        if (self::$lock === null) {
            $path = dirname(__DIR__, 2).'/storage/framework/testing/mysql-3307.lock';
            $directory = dirname($path);
            self::require(is_dir($directory) && ! is_link($directory) && is_writable($directory), 'Unsafe lock directory.');
            self::require(! is_link($path), 'Symlink lock forbidden.');
            $handle = @fopen($path, 'x+');
            if ($handle !== false) {
                chmod($path, 0600);
            } else {
                self::require(is_file($path) && ! is_link($path), 'Unsafe lock path.');
                $handle = fopen($path, 'r+');
            }
            self::require($handle !== false, 'Cannot open lock.');
            $opened = fstat($handle);
            $actual = lstat($path);
            self::require($actual !== false && $opened['ino'] === $actual['ino'] && $opened['dev'] === $actual['dev']
                && ($opened['mode'] & 0170000) === 0100000 && ($opened['mode'] & 0777) === 0600
                && $opened['nlink'] === 1 && function_exists('posix_geteuid') && $opened['uid'] === posix_geteuid(), 'Unsafe lock ownership/mode/identity.');
            self::require(flock($handle, LOCK_EX | LOCK_NB), 'Another local test/schema operation holds the database lock.');
            self::$lock = $handle; // Keep locked for the entire PHP process.
        }
    }

    private static function docker(array $arguments): mixed
    {
        $binary = self::variable('ECOMMERCE_DOCKER_BINARY');
        self::require(str_starts_with($binary, '/') && realpath($binary) === $binary && is_file($binary) && is_executable($binary), 'Reviewed canonical absolute Docker executable required.');
        $expectedHash = self::variable('ECOMMERCE_DOCKER_SHA256');
        clearstatcache(true, $binary);
        $stat = stat($binary);
        self::require($stat !== false && ($stat['mode'] & 0022) === 0, 'Unsafe Docker executable permissions.');
        $fingerprint = [$binary, $expectedHash, $stat['dev'], $stat['ino'], $stat['size'], $stat['mtime'], $stat['ctime'], $stat['mode']];
        if (self::$binaryCache !== $fingerprint) {
            self::require(preg_match('/^[a-f0-9]{64}$/D', $expectedHash) === 1 && hash_file('sha256', $binary) === $expectedHash, 'Docker executable hash mismatch.');
            self::$binaryCache = $fingerprint;
        }
        $home = self::variable('HOME');
        self::require(str_starts_with($home, '/') && is_dir($home), 'Local HOME required for Docker config.');
        $dockerConfig = rtrim($home, '/').'/.docker';
        self::require(self::variable('DOCKER_CONFIG') === '' || self::variable('DOCKER_CONFIG') === $dockerConfig, 'Unexpected Docker config directory.');
        // Never inherit test/migration credentials or the parent environment.
        $environment = ['HOME' => $home, 'DOCKER_CONFIG' => $dockerConfig, 'LANG' => 'C', 'LC_ALL' => 'C'];
        $process = proc_open(array_merge([$binary], $arguments), [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $environment);
        self::require(is_resource($process), 'Docker CLI unavailable.');
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        self::require(proc_close($process) === 0, 'Read-only Docker inspection failed.');

        return json_decode($output, true, 512, JSON_THROW_ON_ERROR);
    }

    public static function container(bool $fresh = false): void
    {
        $key = [self::variable('ECOMMERCE_MYSQL_CONTAINER_ID'), self::variable('ECOMMERCE_MYSQL_IMAGE_ID'), self::variable('ECOMMERCE_DOCKER_BINARY'), self::variable('ECOMMERCE_DOCKER_SHA256'), self::variable('HOME'), self::variable('DOCKER_CONFIG')];
        if (! $fresh && self::$containerCache !== null && self::$containerCache['key'] === $key) {
            return;
        }
        self::$containerCache = null;
        $endpoint = self::docker(['context', 'inspect', '--format', '{{json .Endpoints.docker.Host}}']);
        self::require(is_string($endpoint) && str_starts_with($endpoint, 'unix://'), 'Local Docker Unix endpoint required.');
        // Deliberately exclude Config.Env (which contains credentials).
        $format = '{"id":{{json .Id}},"name":{{json .Name}},"image":{{json .Image}},"running":{{json .State.Running}},"health":{{json .State.Health.Status}},"labels":{{json .Config.Labels}},"mounts":{{json .Mounts}},"ports":{{json .NetworkSettings.Ports}},"network":{{json .HostConfig.NetworkMode}},"privileged":{{json .HostConfig.Privileged}}}';
        $container = self::docker(['inspect', '--type', 'container', '--format', $format, 'ecommerce-mysql-test']);
        self::require($container['id'] === self::variable('ECOMMERCE_MYSQL_CONTAINER_ID') && $container['image'] === self::variable('ECOMMERCE_MYSQL_IMAGE_ID'), 'Container/image identity mismatch.');
        self::require($container['name'] === '/ecommerce-mysql-test' && $container['running'] === true && $container['health'] === 'healthy' && $container['privileged'] === false, 'Unexpected container state.');
        self::require(($container['labels']['com.docker.compose.project'] ?? '') === 'ecommerce-integration-test' && ($container['labels']['com.docker.compose.service'] ?? '') === 'mysql-test', 'Unexpected Compose ownership.');
        self::require($container['network'] === 'ecommerce-integration-test_default', 'Unexpected Docker network.');
        self::require(($container['ports']['3306/tcp'] ?? null) === [['HostIp' => '127.0.0.1', 'HostPort' => '3307']], 'Unexpected MySQL port binding.');
        foreach ($container['ports'] as $port => $bindings) {
            self::require($port === '3306/tcp' || $bindings === null, 'Unexpected published port.');
        }
        self::require(count($container['mounts']) === 1, 'Unexpected container mounts.');
        $mount = $container['mounts'][0];
        self::require($mount['Type'] === 'volume' && $mount['Name'] === 'ecommerce-integration-test_mysql_test_data' && $mount['Destination'] === '/var/lib/mysql', 'Unexpected data volume.');
        self::$containerCache = ['key' => $key, 'metadata' => $container, 'endpoint' => $endpoint];
    }

    public static function configuration(array $config, ?string $profile = null): void
    {
        foreach (['driver' => 'mysql', 'host' => self::HOST, 'database' => self::DATABASE, 'username' => self::username($profile)] as $key => $value) {
            self::require(($config[$key] ?? null) === $value, 'Unsafe effective connection '.$key.'.');
        }
        self::require((string) ($config['port'] ?? '') === self::PORT && ($config['password'] ?? '') === self::password($profile), 'Unexpected effective port/credentials.');
        foreach (['url', 'unix_socket', 'read', 'write', 'options', 'modes', 'timezone', 'isolation_level', 'init_commands'] as $key) {
            self::require(empty($config[$key]), 'Unsafe connection option '.$key.'.');
        }
        self::require(($config['strict'] ?? false) === true && ($config['prefix'] ?? '') === '' && ($config['charset'] ?? '') === 'utf8mb4' && ($config['collation'] ?? '') === 'utf8mb4_unicode_ci', 'Unsafe SQL configuration.');
    }

    private static function operationRequest(string $profile, bool $configureSession, bool $readOnlyPreflight): void
    {
        self::require(in_array($profile, ['test', 'migration'], true), 'Unknown credential profile.');
        if ($readOnlyPreflight) {
            self::readOnlyRequest($profile, $configureSession);
        } else {
            self::require(self::variable('ECOMMERCE_MYSQL_PREFLIGHT_OPT_IN') === '', 'Preflight opt-in cannot authorize initialization, migration, or integration tests.');
            if ($profile === 'migration') {
                self::require(in_array(self::variable('ECOMMERCE_MYSQL_MIGRATION_OPT_IN'), ['initialize', 'preview', 'apply'], true), 'Migration profile requires an explicit initialize/preview/apply opt-in.');
            }
        }
    }

    private static function pdoFactory(array $config, string $profile, bool $configureSession, bool $readOnlyPreflight): Closure
    {
        // Capture the connection's security context by value. Never consult shared
        // mode/profile flags when this lazy closure is eventually executed.
        return static function () use ($config, $profile, $configureSession, $readOnlyPreflight): PDO {
            self::require(self::variable('ECOMMERCE_MYSQL_ALLOW_DB') === '1', 'Database access requires separate approval/opt-in.');
            self::operationRequest($profile, $configureSession, $readOnlyPreflight);
            self::environment($profile);
            self::configuration($config, $profile);
            self::container(true);
            try {
                $pdo = new PDO('mysql:host='.self::HOST.';port='.self::PORT.';dbname='.self::DATABASE.';charset=utf8mb4', $config['username'], $config['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false, PDO::ATTR_PERSISTENT => false]);
            } catch (Throwable) {
                throw new RuntimeException('MySQL safety: dedicated connection failed (credentials omitted).');
            }
            self::identity($pdo, $profile, $readOnlyPreflight);
            if ($configureSession) {
                (new GuardedMySqlSession)->apply($pdo, $config);
                self::session($pdo, $config, $profile);
            }

            return $pdo;
        };
    }

    public static function application(string $profile = 'test', bool $configureSession = false, bool $readOnlyPreflight = false): Application
    {
        self::require(in_array($profile, ['test', 'migration'], true), 'Unknown credential profile.');
        self::require(self::$lock === null || self::$profile === $profile, 'Credential profile cannot change within a process.');
        GuardedMySqlSession::verifyApi();
        self::operationRequest($profile, $configureSession, $readOnlyPreflight);
        self::environment($profile);
        $app = require dirname(__DIR__, 2).'/bootstrap/app.php';
        $app->useEnvironmentPath(__DIR__.'/.mysql-no-env');
        self::require(! $app->configurationIsCached(), 'Cached configuration forbidden.');
        $app->afterBootstrapping(LoadConfiguration::class, function (Application $app) use ($profile, $configureSession, $readOnlyPreflight): void {
            self::require($app->environment('testing') && $app['config']->get('database.default') === 'mysql', 'Unsafe Laravel environment/default connection.');
            $config = $app['config']->get('database.connections.mysql');
            self::require(($config['username'] ?? null) === 'root' && ($config['password'] ?? null) === '', 'Unexpected inherited credentials.');
            $config['username'] = self::username($profile);
            $config['password'] = self::password($profile);
            self::configuration($config, $profile);
            foreach ($app['config']->get('database.connections') as $connection) {
                self::require(empty($connection['url']) && ! array_key_exists('read', $connection) && ! array_key_exists('write', $connection), 'Unexpected URL/read-write connection.');
            }
            $app['config']->set('database.connections', ['mysql' => $config]);
            foreach (['cache.default' => 'array', 'session.driver' => 'array', 'queue.default' => 'sync', 'mail.default' => 'array', 'broadcasting.default' => 'null'] as $key => $value) {
                $app['config']->set($key, $value);
            }
            self::require(! $app->resolved('db'), 'Database manager resolved before guard installation.');
            $app->resolving('db', function ($manager) use ($app, $profile, $configureSession, $readOnlyPreflight): void {
                self::require($manager->getConnections() === [], 'Connections resolved before guard installation.');
                $manager->extend('mysql', function (array $config, string $name) use ($app, $profile, $configureSession, $readOnlyPreflight): MySqlConnection {
                    self::require($name === 'mysql' && array_keys($app['config']->get('database.connections')) === ['mysql'], 'Unexpected database connection.');
                    self::configuration($config, $profile);

                    return new GuardedMySqlConnection(self::pdoFactory($config, $profile, $configureSession, $readOnlyPreflight), self::DATABASE, '', array_merge($config, ['name' => $name]));
                });
            });
        });
        $app->make(Kernel::class)->bootstrap();
        self::connection($app, $profile);
        // Publish shared defaults only after request, environment, effective config,
        // bootstrap, and resolver checks all succeeded. Existing closures use copies.
        self::$profile = $profile;
        self::$configureSession = $configureSession;
        self::$readOnlyPreflight = $readOnlyPreflight;

        return $app;
    }

    public static function session(PDO $pdo, array $config, ?string $profile = null): void
    {
        self::configuration($config, $profile);
        GuardedMySqlSession::verifyApi();
        $row = $pdo->query('SELECT @@SESSION.sql_mode AS mode, @@SESSION.character_set_client AS client_charset, @@SESSION.character_set_connection AS connection_charset, @@SESSION.character_set_results AS results_charset, @@SESSION.collation_connection AS collation')->fetch(PDO::FETCH_ASSOC);
        $expected = explode(',', (new GuardedMySqlSession)->expectedMode($pdo, $config));
        $actual = explode(',', $row['mode']);
        sort($expected);
        sort($actual);
        self::require($actual === $expected && $row['client_charset'] === $config['charset'] && $row['connection_charset'] === $config['charset']
            && $row['results_charset'] === $config['charset'] && $row['collation'] === $config['collation'], 'Session strict mode/charset/collation differs from Laravel configuration.');
    }

    public static function identity(PDO $pdo, ?string $profile = null, ?bool $readOnlyPreflight = null): void
    {
        $profile ??= self::$profile;
        $readOnlyPreflight ??= self::$readOnlyPreflight;
        if ($readOnlyPreflight) {
            $row = $pdo->query(MySqlReadOnlyPreflightPolicy::IDENTITY_SQL)->fetch(PDO::FETCH_ASSOC);
            self::require(is_array($row), 'Missing read-only identity result.');
            MySqlReadOnlyPreflightPolicy::identity($row, $profile, self::variable('ECOMMERCE_MYSQL_SERVER_UUID'), self::variable('ECOMMERCE_MYSQL_EXPECTED_SOURCE_HOST'));
        } else {
            $row = $pdo->query('SELECT DATABASE() AS db, VERSION() AS version, @@server_uuid AS uuid, CURRENT_USER() AS account, CURRENT_ROLE() AS roles, @@GLOBAL.partial_revokes AS partial_revokes, @@GLOBAL.mandatory_roles AS mandatory_roles, @@GLOBAL.activate_all_roles_on_login AS activate_all_roles')->fetch(PDO::FETCH_ASSOC);
        }
        self::require($row['db'] === self::DATABASE && $row['version'] === self::VERSION && $row['uuid'] === self::variable('ECOMMERCE_MYSQL_SERVER_UUID'), 'Database/server identity mismatch.');
        self::require($row['account'] === MySqlGrantPolicy::account($profile), 'Authenticated account does not match the approved profile.');
        self::require($row['roles'] === 'NONE' && $row['mandatory_roles'] === '' && (int) $row['activate_all_roles'] === 0, 'Unexpected active or mandatory role configuration.');
        MySqlGrantPolicy::validate($pdo->query('SHOW GRANTS')->fetchAll(PDO::FETCH_COLUMN), $profile, $row['account'], (int) $row['partial_revokes']);
    }

    public static function schema(PDO $pdo): void
    {
        self::identity($pdo);
        $tables = $pdo->query("SELECT TABLE_NAME, ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('products','sellers','categories')")->fetchAll(PDO::FETCH_KEY_PAIR);
        self::require(count($tables) === 3 && count(array_filter($tables, fn ($engine) => $engine !== 'InnoDB')) === 0, 'Required transactional schema missing.');
        $price = $pdo->query("SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'products' AND COLUMN_NAME = 'price'")->fetchColumn();
        self::require(strtolower((string) $price) === 'bigint unsigned', 'price must be BIGINT UNSIGNED.');
        $foreignKeys = $pdo->query("SELECT COLUMN_NAME, REFERENCED_TABLE_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'products' AND REFERENCED_TABLE_NAME IS NOT NULL")->fetchAll(PDO::FETCH_KEY_PAIR);
        self::require(($foreignKeys['seller_id'] ?? '') === 'sellers' && ($foreignKeys['category_id'] ?? '') === 'categories', 'Required product foreign keys missing.');
        $applied = $pdo->query('SELECT migration FROM migrations')->fetchAll(PDO::FETCH_COLUMN);
        foreach (glob(dirname(__DIR__, 2).'/database/migrations/*.php') as $migration) {
            self::require(in_array(pathinfo($migration, PATHINFO_FILENAME), $applied, true), 'Pending migration; use separately approved schema preparation.');
        }
        foreach (['products', 'sellers'] as $table) {
            self::require((int) $pdo->query('SELECT COUNT(*) FROM '.$table)->fetchColumn() === 0, 'Unexpected persistent fixture data; no automatic cleanup.');
        }
    }
}

if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    exit(MySqlSafety::preflight($argv));
}
