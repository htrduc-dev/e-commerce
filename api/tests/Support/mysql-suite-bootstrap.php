<?php

namespace Tests\Support;

use PHPUnit\Event\TestRunner\ExecutionStarted;
use PHPUnit\Event\TestRunner\ExecutionStartedSubscriber;
use PHPUnit\Event\TestSuite\Loaded;
use PHPUnit\Event\TestSuite\LoadedSubscriber;
use PHPUnit\Event\TestSuite\TestSuite;
use PHPUnit\Runner\Extension\Extension;
use PHPUnit\Runner\Extension\Facade;
use PHPUnit\Runner\Extension\ParameterCollection;
use PHPUnit\TextUI\Configuration\Configuration;
use PHPUnit\TextUI\Configuration\Registry;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use RuntimeException;
use Throwable;

require_once dirname(__DIR__, 2).'/vendor/autoload.php';
require_once __DIR__.'/mysql-preflight.php';

final class MySqlSuiteGuard
{
    public static bool $executionChecked = false;

    private static array $inventory = [];

    public static function inventory(Configuration $configuration): void
    {
        $root = dirname(__DIR__, 2);
        if (! $configuration->hasConfigurationFile() || realpath($configuration->configurationFile()) !== $root.'/phpunit.mysql.xml'
            || ! $configuration->hasBootstrap() || realpath($configuration->bootstrap()) !== __FILE__ || $configuration->noExtensions()) {
            throw new RuntimeException('MySQL safety: mandatory MySQL configuration/bootstrap/extensions required.');
        }
        $paths = array_merge([$root.'/tests/MySql'], $configuration->cliArguments());
        $files = [];
        foreach ($paths as $path) {
            $resolved = realpath($path);
            if ($resolved === false || is_link($path)) {
                throw new RuntimeException('MySQL safety: unreadable/symlink test inventory path.');
            }
            if (is_dir($resolved)) {
                if ($resolved !== $root.'/tests/MySql') {
                    throw new RuntimeException('MySQL safety: direct directory execution is limited to tests/MySql.');
                }
                foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($resolved, \FilesystemIterator::SKIP_DOTS)) as $entry) {
                    if ($entry->isLink()) {
                        throw new RuntimeException('MySQL safety: symlink in test inventory.');
                    }
                    if ($entry->isFile() && $entry->getExtension() === 'php' && $entry->getPathname() !== $root.'/tests/MySql/MySqlTestCase.php') {
                        $files[$entry->getRealPath()] = true;
                    }
                }
            } else {
                if (pathinfo($resolved, PATHINFO_EXTENSION) !== 'php' || $resolved === $root.'/tests/MySql/MySqlTestCase.php') {
                    throw new RuntimeException('MySQL safety: unsupported direct test file.');
                }
                $files[$resolved] = true;
            }
        }
        // Tokenization is read-only: never include/eval a candidate to learn its class.
        foreach (array_keys($files) as $file) {
            self::inspectSource($file);
        }
    }

    private static function inspectSource(string $file): void
    {
        $source = file_get_contents($file);
        if ($source === false) {
            throw new RuntimeException('MySQL safety: cannot read test inventory file.');
        }
        $tokens = array_values(array_filter(token_get_all($source, TOKEN_PARSE), static fn ($token) => ! is_array($token)
            || ! in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)));
        $namespace = '';
        $classes = 0;
        foreach ($tokens as $index => $token) {
            if (! is_array($token)) {
                continue;
            }
            if (in_array($token[0], [T_INTERFACE, T_TRAIT, T_ENUM], true)) {
                throw new RuntimeException('MySQL safety: unsupported declaration in '.basename($file).'.');
            }
            if ($token[0] === T_NAMESPACE) {
                if ($namespace !== '' || ! is_array($tokens[$index + 1] ?? null) || ($tokens[$index + 2] ?? null) !== ';') {
                    throw new RuntimeException('MySQL safety: only one unbracketed namespace is supported in '.basename($file).'.');
                }
                $namespace = $tokens[$index + 1][1];
            }
            if ($token[0] === T_USE) {
                $import = $tokens[$index + 1] ?? null;
                if (! is_array($import) || ! in_array($import[0], [T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_STRING], true)
                    || ($tokens[$index + 2] ?? null) !== ';') {
                    throw new RuntimeException('MySQL safety: grouped/aliased/closure use is unsupported in '.basename($file).'.');
                }
                $importName = ltrim($import[1], '\\');
                if (str_ends_with($importName, 'MySqlTestCase') && $importName !== 'Tests\\MySql\\MySqlTestCase') {
                    throw new RuntimeException('MySQL safety: MySqlTestCase import must refer to the mandatory harness.');
                }
            }
            if ($token[0] !== T_CLASS || (is_array($tokens[$index - 1] ?? null) && $tokens[$index - 1][0] === T_DOUBLE_COLON)) {
                continue;
            }
            $name = $tokens[$index + 1] ?? null;
            $extends = $tokens[$index + 2] ?? null;
            $parent = $tokens[$index + 3] ?? null;
            $class = $namespace.'\\'.(is_array($name) ? $name[1] : '<anonymous>');
            if (! is_array($name) || $name[0] !== T_STRING || ! is_array($extends) || $extends[0] !== T_EXTENDS
                || ! is_array($parent) || ! (($parent[1] === 'MySqlTestCase' && $namespace === 'Tests\\MySql')
                    || $parent[1] === '\\Tests\\MySql\\MySqlTestCase') || ($tokens[$index + 4] ?? null) !== '{') {
                throw new RuntimeException('MySQL safety: unsupported test class '.$class.'; direct MySqlTestCase inheritance required.');
            }
            if (isset(self::$inventory[$class])) {
                throw new RuntimeException('MySQL safety: duplicate inventory class '.$class.'.');
            }
            self::$inventory[$class] = ['file' => $file, 'hash' => hash('sha256', $source)];
            $classes++;
        }
        if ($classes !== 1) {
            throw new RuntimeException('MySQL safety: exactly one named harness test class required in '.basename($file).'.');
        }
    }

    public static function check(TestSuite $suite): void
    {
        $context = 'suite';
        try {
            if ($suite->count() !== count($suite->tests())) {
                throw new RuntimeException('MySQL safety: unsupported tests omitted from PHPUnit event collection.');
            }
            foreach ($suite->tests() as $test) {
                if (! $test->isTestMethod()) {
                    throw new RuntimeException('MySQL safety: unsupported discovered test type.');
                }
                $context = $test->className();
                $item = self::$inventory[$context] ?? null;
                if ($item === null || (new ReflectionClass($context))->getFileName() !== $item['file']
                    || hash_file('sha256', $item['file']) !== $item['hash']) {
                    throw new RuntimeException('MySQL safety: test class '.$context.' does not match the pre-discovery inventory.');
                }
                MySqlSafety::testClass($context);
            }
        } catch (Throwable $exception) {
            // PHPUnit 12 catches subscriber exceptions and continues with a warning.
            // Exit explicitly so no unsafe test setup is allowed to proceed.
            $message = $exception instanceof RuntimeException && str_starts_with($exception->getMessage(), 'MySQL safety:')
                ? $exception->getMessage() : 'MySQL safety: failed to validate test class '.$context.'.';
            fwrite(STDERR, 'BLOCKED: '.$message.PHP_EOL);
            exit(1);
        }
    }
}

final class MySqlSuiteLoaded implements LoadedSubscriber
{
    public function notify(Loaded $event): void
    {
        MySqlSuiteGuard::check($event->testSuite());
    }
}

final class MySqlExecutionStarted implements ExecutionStartedSubscriber
{
    public function notify(ExecutionStarted $event): void
    {
        MySqlSuiteGuard::check($event->testSuite());
        MySqlSuiteGuard::$executionChecked = true;
    }
}

final class MySqlSuiteExtension implements Extension
{
    public function bootstrap(Configuration $configuration, Facade $facade, ParameterCollection $parameters): void
    {
        $facade->registerSubscribers(new MySqlSuiteLoaded, new MySqlExecutionStarted);
    }
}

// PHPUnit initializes Registry before loading the configured bootstrap, and seals
// the event facade only after extension registration. No candidate file is run here.
MySqlSuiteGuard::inventory(Registry::get());
