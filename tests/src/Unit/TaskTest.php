<?php

declare(strict_types=1);

namespace NuvoleWeb\Robo\Tests\Unit;

use Consolidation\Config\Loader\YamlConfigLoader;
use League\Container\ContainerAwareInterface;
use League\Container\ContainerAwareTrait;
use NuvoleWeb\Robo\Task\Config\loadTasks;
use NuvoleWeb\Robo\Task\Config\Php\AppendConfiguration;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Robo\Collection\CollectionBuilder;
use Robo\Config\Config;
use Robo\Contract\TaskInterface;
use Robo\Robo;
use Robo\TaskAccessor;
use Robo\Tasks;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\StringInput;
use Symfony\Component\Console\Output\NullOutput;

class TaskTest extends TestCase implements ContainerAwareInterface {

  use loadTasks;
  use TaskAccessor;
  use ContainerAwareTrait;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    $container = Robo::createDefaultContainer(NULL, new NullOutput());
    $this->setContainer($container);
  }

  /**
   * Tests token replacement.
   */
  public function testTokenReplacement(): void {
    $definition = new InputDefinition([
      new InputOption('config', 'c', InputOption::VALUE_REQUIRED),
      new InputOption('override', 'o', InputOption::VALUE_IS_ARRAY | InputOption::VALUE_OPTIONAL),
    ]);

    $input = new StringInput("--config='{$this->getFixturePath('config-with-tokens.yml')}'");
    $input->bind($definition);
    $this->initializeConfiguration($input);

    // Check that static configurations are correct.
    $this->assertEquals('bar', $this->config('foo'));
    $this->assertEquals('some_value', $this->config('bar.baz'));
    // Check that tokens were resolved.
    $this->assertEquals('bar', $this->config('qux.var1'));
    $this->assertEquals('some_value', $this->config('qux.var2'));
  }

  /**
   * Scaffold collection builder.
   *
   * @return \Robo\Collection\CollectionBuilder
   *   Collection builder.
   */
  public function collectionBuilder(): CollectionBuilder {
    $empty_robo_file = new Tasks();

    return CollectionBuilder::create($this->getContainer(), $empty_robo_file);
  }

  /**
   * Test task run.
   */
  #[DataProvider('appendTestProvider')]
  public function testTaskAppendConfiguration($config_file, $source_file, $processed_file): void {
    $source = $this->getFixturePath($source_file);
    $filename = $this->getFixturePath('tmp/' . $source_file);
    copy($source, $filename);

    $config = $this->getConfig($config_file);
    $command = $this->taskAppendConfiguration($filename, $config)->run();
    $this->assertNotEmpty($command);

    // Make sure we get the same output regardless of the number of runs.
    $this->runTimes(20, $this->taskAppendConfiguration($filename, $config));
    $this->assertEquals(trim(file_get_contents($filename)), trim(file_get_contents($this->getFixturePath($processed_file))));
  }

  /**
   * Test task run.
   */
  #[DataProvider('prependTestProvider')]
  public function testTaskPrependConfiguration($config_file, $source_file, $processed_file): void {
    $source = $this->getFixturePath($source_file);
    $filename = $this->getFixturePath('tmp/' . $source_file);
    copy($source, $filename);

    $config = $this->getConfig($config_file);
    $command = $this->taskPrependConfiguration($filename, $config)->run();
    $this->assertNotEmpty($command);

    // Make sure we get the same output regardless of the number of runs.
    $this->runTimes(20, $this->taskPrependConfiguration($filename, $config));
    $this->assertEquals(trim(file_get_contents($filename)), trim(file_get_contents($this->getFixturePath($processed_file))));
  }

  /**
   * Test task run.
   */
  #[DataProvider('writeTestProvider')]
  public function testTaskWriteConfiguration($config_file, $processed_file): void {
    $filename = $this->getFixturePath('tmp/' . $processed_file);
    $config = $this->getConfig($config_file);
    $command = $this->taskWriteConfiguration($filename, $config)->run();
    $this->assertNotEmpty($command);

    // Make sure we get the same output regardless of the number of runs.
    $this->runTimes(20, $this->taskWriteConfiguration($filename, $config));
    $this->assertEquals(trim(file_get_contents($filename)), trim(file_get_contents($this->getFixturePath($processed_file))));
  }

  /**
   * Test setting processing.
   */
  #[DataProvider('appendTestProvider')]
  public function testProcess($config_file, $source_file, $processed_file): void {
    $filename = $this->getFixturePath($source_file);
    $config = $this->getConfig($config_file);

    $processor = new AppendConfiguration($filename, $config);
    $content = file_get_contents($filename);
    $processed = $processor->process($content);
    $this->assertEquals(trim($processed), trim(file_get_contents($this->getFixturePath($processed_file))));
  }

  /**
   * Data provider.
   *
   * @return array
   *   Test data.
   */
  public static function appendTestProvider(): array {
    return [
      ['1-config.yml', '1-input.php', '1-output-append.php'],
      ['2-config.yml', '2-input.php', '2-output-append.php'],
    ];
  }

  /**
   * Data provider.
   *
   * @return array
   *   Test data.
   */
  public static function prependTestProvider(): array {
    return [
      ['1-config.yml', '1-input.php', '1-output-prepend.php'],
      ['2-config.yml', '2-input.php', '2-output-prepend.php'],
    ];
  }

  /**
   * Data provider.
   *
   * @return array
   *   Test data.
   */
  public static function writeTestProvider(): array {
    return [
      ['3-config.yml', '3-output-write.php'],
    ];
  }

  /**
   * Get configuration object from given fixture.
   *
   * @param string $fixture
   *   Fixture file name.
   *
   * @return \Robo\Config\Config
   *   Configuration object.
   */
  private function getConfig(string $fixture): Config {
    $config = new Config();
    $loader = new YamlConfigLoader();
    $loader->load($this->getFixturePath($fixture));
    $config->replace($loader->export());

    return $config;
  }

  /**
   * Get fixture file path.
   *
   * @param string $name
   *   Fixture file name.
   *
   * @return string
   *   Fixture file path.
   */
  private function getFixturePath(string $name): string {
    return dirname(__DIR__, 2) . '/fixtures/' . $name;
  }

  /**
   * Run the given task for the given times.
   *
   * @param int $times
   *   Times task should be ran.
   * @param \Robo\Contract\TaskInterface $task
   *   Task to run.
   */
  private function runTimes(int $times, TaskInterface $task): void {
    $i = $times;
    while ($i >= 0) {
      $task->run();
      $i--;
    }
  }

}
