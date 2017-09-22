<?php

namespace LaraSpells\Generator\Commands;

use Illuminate\Console\Command;
use Illuminate\Routing\Router;
use LaraSpells\Generator\Exceptions\InvalidTemplateException;
use LaraSpells\Generator\Generator;
use LaraSpells\Generator\Generators\CodeGenerator;
use LaraSpells\Generator\Generators\ControllerGenerator;
use LaraSpells\Generator\Generators\CreateRequestGenerator;
use LaraSpells\Generator\Generators\DocblockGenerator;
use LaraSpells\Generator\Generators\MigrationGenerator;
use LaraSpells\Generator\Generators\ModelGenerator;
use LaraSpells\Generator\Generators\RouteGenerator;
use LaraSpells\Generator\Generators\ServiceProviderGenerator;
use LaraSpells\Generator\Generators\UpdateRequestGenerator;
use LaraSpells\Generator\Generators\ViewCreateGenerator;
use LaraSpells\Generator\Generators\ViewDetailGenerator;
use LaraSpells\Generator\Generators\ViewEditGenerator;
use LaraSpells\Generator\Generators\ViewListGenerator;
use LaraSpells\Generator\Schema\Schema;
use LaraSpells\Generator\Schema\Table;
use LaraSpells\Generator\Stub;
use LaraSpells\Generator\Template;
use LaraSpells\Generator\Traits\TemplateUtil;
use Symfony\Component\Yaml\Yaml;

class GenerateCommand extends SchemaBasedCommand
{
    use Concerns\GeneratorBinder,
        Concerns\RouteUtils,
        Concerns\MigrationUtils,
        Concerns\ConfigUtils,
        Concerns\MissingDisks,
        Concerns\PublicFilesPublisher,
        Concerns\SuggestionUtils;

    const HOOK_BEFORE_GENERATE_CRUDS    = 'BEFORE_GENERATE_CRUDS';
    const HOOK_BEFORE_EACH_CRUD         = 'BEFORE_EACH_CRUD';
    const HOOK_AFTER_EACH_CRUD          = 'AFTER_EACH_CRUD';
    const HOOK_AFTER_GENERATE_CRUDS     = 'AFTER_GENERATE_CRUDS';
    const HOOK_BEFORE_REPORTS           = 'BEFORE_REPORTS';
    const HOOK_END                      = 'END';

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = '
        spell:generate
        {schema : Path to schema (yml) file}
        {--replace-all : Replace existing files}
        {--askme : Ask before generate existing files}
        {--no-migration : Generate without migration}
        {--no-cruds : Generate without cruds}
        {--no-public : Generate without publish template public files}
        {--no-views : Generate without publish template view files}
        {--silent : Generate without showing affected files}
        {--t|table= : Generate specific table}
    ';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate CRUD from given schema';

    protected $router;
    protected $menu = [];
    protected $missingRoutes = [];
    protected $generatedFiles = [];
    protected $modifiedFiles = [];
    protected $addedFiles = [];
    protected $generatedMigrations = [];
    protected $suggestions = [];
    protected $extensions = [];

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct(Router $router)
    {
        parent::__construct();
        $this->router = $router;
        app()->instance(static::class, $this);
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $schemaFile = $this->argument('schema');
        // Initialize template and schema.
        $this->initializeSchema($schemaFile);
        // Register schema extensions.
        foreach($this->getSchema()->getExtensions() as $extension) {
            $this->registerExtension($extension);
        }

        // Generate CRUDS.
        $tables = $this->getTablesToGenerate();
        $this->applyHook(self::HOOK_BEFORE_GENERATE_CRUDS, [$tables]);
        if (!$this->option('no-cruds')) {
            foreach($this->getTablesToGenerate() as $table) {
                $this->reinitializeCrudGenerators($table);

                // Generate create table migration
                $migration = !$this->option('no-migration');
                if ($migration) {
                    $this->generateMigrationForTable($table);
                }

                // Generate CRUD
                if ($table->hasCrud()) {
                    $this->applyHook(self::HOOK_BEFORE_EACH_CRUD, [$table]);
                    $this->generateCrudForTable($table);
                    $this->applyHook(self::HOOK_AFTER_EACH_CRUD, [$table]);
                }
            }
        }
        $this->applyHook(self::HOOK_AFTER_GENERATE_CRUDS, [$tables]);

        // Generate or publish another files.
        $this->generateAddedRoutes();
        $this->persistConfigs();
        // $this->generateProvider();
        if (!$this->option('no-views')) {
            $this->publishViewFiles();
        }
        if (!$this->option('no-public')) {
            $this->publishPublicFiles();
        }

        // Show results
        $this->applyHook(self::HOOK_BEFORE_REPORTS);
        $this->showResult();
        $this->applyHook(self::HOOK_END);
    }

    protected function reinitializeCrudGenerators(Table $table)
    {
        app()->instance(Table::class, $table);
        $this->setGeneratorInstance(ControllerGenerator::class, $this->makeGenerator(ControllerGenerator::class));
        $this->setGeneratorInstance(ModelGenerator::class, $this->makeGenerator(ModelGenerator::class));
        $this->setGeneratorInstance(ViewListGenerator::class, $this->makeGenerator(ViewListGenerator::class, [
            'stubContent' => $this->getTemplate()->getStubContent('page-list.stub')
        ]));
        $this->setGeneratorInstance(ViewDetailGenerator::class, $this->makeGenerator(ViewDetailGenerator::class, [
            'stubContent' => $this->getTemplate()->getStubContent('page-detail.stub')
        ]));
        $this->setGeneratorInstance(ViewCreateGenerator::class, $this->makeGenerator(ViewCreateGenerator::class, [
            'stubContent' => $this->getTemplate()->getStubContent('form-create.stub')
        ]));
        $this->setGeneratorInstance(ViewEditGenerator::class, $this->makeGenerator(ViewEditGenerator::class, [
            'stubContent' => $this->getTemplate()->getStubContent('form-edit.stub')
        ]));
    }

    /**
     * Add new extension
     *
     * @param  string $extensions
     * @return void
     */
    public function registerExtension($extension)
    {
        if (!is_subclass_of($extension, Extension::class)) {
            throw new InvalidArgumentException("Extension '{$extension}' must be subclass of '".Extension::class."'.");
        }

        $extension = app($extension);
        $extension->register();
        $this->hook(self::HOOK_BEFORE_GENERATE_CRUDS, [$extension, 'beforeGenerateCruds']);
        $this->hook(self::HOOK_AFTER_GENERATE_CRUDS, [$extension, 'afterGenerateCruds']);
        $this->hook(self::HOOK_BEFORE_EACH_CRUD, [$extension, 'beforeGenerateEachCrud']);
        $this->hook(self::HOOK_AFTER_EACH_CRUD, [$extension, 'afterGenerateEachCrud']);
        $this->hook(self::HOOK_END, [$extension, 'onEnd']);
        $this->extensions[] = $extension;
    }

    /**
     * Get added extensions
     *
     * @return array
     */
    public function getExtensions()
    {
        return $this->extensions;
    }

    /**
     * Get tables to generate
     *
     * @return array of LaraSpells\Generator\Schema\Table
     */
    protected function getTablesToGenerate()
    {
        $specificTable = $this->option('table');
        if ($specificTable) {
            $table = $this->getSchema()->getTable($specificTable);
            if (!$table) {
                throw new \InvalidArgumentException("Table '{$specificTable}' is not defined in schema");
            }
            $tables = [$table];
        } else {
            $tables = $this->getSchema()->getTables();
        }

        return $tables;
    }

    /**
     * Run generator.
     *
     * @param  string $class
     * @param  array $params
     * @return string
     */
    public function runGenerator($class, array $params = [])
    {
        $generator = $this->makeGenerator($class, $params);
        return $generator->generateCode();
    }

    /**
     * Show results
     *
     * @return void
     */
    protected function showResult()
    {
        // Show info count affected files
        print(PHP_EOL);
        $this->info("DONE!");
        $countGenerateds = count($this->generatedFiles);
        $countAddeds = count($this->addedFiles);
        $countModifieds = count($this->modifiedFiles);
        $this->info("> {$countGenerateds} ".($countGenerateds > 1? 'files generateds.' : 'file generated.'));
        $this->info("> {$countAddeds} ".($countAddeds > 1? 'files addeds.' : 'file added.'));
        $this->info("> {$countModifieds} ".($countModifieds > 1? 'files overwriteds' : 'file overwrited'));

        // Show suggestions
        if ($missingDisksSuggestion = $this->getMissingDisksSuggestion()) {
            $this->addSuggestion($missingDisksSuggestion);
        }
        $this->showSuggestions();
    }

    /**
     * Generate CRUD for specific table
     *
     * @param  LaraSpells\Generator\Schema\Table $table
     * @return void
     */
    protected function generateCrudForTable(Table $table)
    {
        app()->instance(Table::class, $table);

        $this->generateControllerForTable($table);
        $this->generateCreateRequestForTable($table);
        $this->generateUpdateRequestForTable($table);
        $this->generateModelForTable($table);
        $this->generateViews($table);
        $this->addCrudMissingRoutes($table);
        $this->addConfigMenu($table->getRouteListName(), $table->getLabel(), ['icon' => $table->get('icon')]);
    }

    /**
     * Generate Migration for specific table
     *
     * @return void
     */
    protected function generateMigrationForTable(Table $table)
    {
        $existingMigrationFile = $this->getExistingMigrationFile($table->getName());
        $filePath = $table->getMigrationPath();
        $tableName = $table->getName();
        $overwrite = false;
        $shouldGenerate = true;
        if ($existingMigrationFile) {
            $shouldGenerate = $this->checkShouldWrite(
                $existingMigrationFile,
                "Migration file that create table \"{$tableName}\" already exists. Do you want to replace it?",
                true
            );
            $filePath = $existingMigrationFile;
            $overwrite = true;
        }

        if (!$shouldGenerate) {
            return;
        }

        $content = $this->runGenerator(MigrationGenerator::class);
        $this->writeFile($filePath, $content);
        if ($overwrite) {
            $this->addModifiedFile($filePath);
        } else {
            $this->generatedMigrations[] = $filePath;
            $this->addGeneratedFile($filePath);
        }
    }

    protected function generateControllerForTable(Table $table)
    {
        $filePath = $table->getControllerPath();
        $content = $this->getGeneratorController()->setTableSchema($table)->generateCode();
        $this->generateFile($filePath, $content);
    }

    protected function generateCreateRequestForTable(Table $table)
    {
        $filePath = $table->getCreateRequestPath();
        $content = $this->runGenerator(CreateRequestGenerator::class);
        $this->generateFile($filePath, $content);
    }

    protected function generateUpdateRequestForTable(Table $table)
    {
        $filePath = $table->getUpdateRequestPath();
        $content = $this->runGenerator(UpdateRequestGenerator::class);
        $this->generateFile($filePath, $content);
    }

    protected function generateModelForTable(Table $table)
    {
        $filePath = $table->getModelPath();
        $content = $this->getGeneratorModel()->setTableSchema($table)->generateCode();
        $this->generateFile($filePath, $content);
    }

    protected function generateViews(Table $table)
    {
        $views = [
            $table->getViewListPath() => $this->getGeneratorViewList()->generateCode(),
            $table->getViewDetailPath() => $this->getGeneratorViewDetail()->generateCode(),
            $table->getViewCreatePath() => $this->getGeneratorViewCreate()->generateCode(),
            $table->getViewEditPath() => $this->getGeneratorViewEdit()->generateCode(),
        ];

        foreach($views as $path => $content) {
            $this->generateFile($path, $content);
        }
    }

    protected function generateProvider()
    {
        $filePath = $this->getSchema()->getServiceProviderPath();
        $content = $this->runGenerator(ServiceProviderGenerator::class);
        $this->generateFile($filePath, $content);
    }

    protected function publishViewFiles()
    {
        $template = $this->getTemplate();
        $viewPath = $this->getSchema()->getViewpath();
        $templateViewDir = $template->getDirectory().'/'.$template->getFolderView();
        $viewFiles = $template->getViewFiles();
        $viewNamespace = $this->getSchema()->getViewNamespace();
        $configKey = str_replace("/", ".", preg_replace("/\.php$/", "", $this->getSchema()->getConfigFile()));
        $data = [
            'view_namespace' => $viewNamespace? $viewNamespace.'::' : '',
            'config_key' => $configKey,
            'schema' => $this->getSchema()->toArray()
        ];
        foreach($viewFiles as $viewFile) {
            $dest = $viewPath.'/'.ltrim(str_replace($templateViewDir, "", $viewFile), '/');
            $stub = new Stub(file_get_contents($viewFile), $data);
            $content = $stub->render();
            $this->generateFile($dest, $content);
        }
    }

    protected function publishPublicFiles()
    {
        $this->addTemplatePublicFiles('');
        $publicDir = "public";
        $publicFiles = $this->getAddedPublicFiles();
        foreach($publicFiles as $to => $from) {
            $this->copyFile($from, $publicDir.'/'.$to);
        }
    }

    protected function checkShouldWrite($file, $question = null, $defaultValue = false)
    {
        if ($this->option('replace-all')) {
            return true;
        }

        $ask = $this->option('askme');
        $fileExists = $this->hasFile($file);
        if (!$ask) {
            return !$fileExists;
        } else {
            if (!$question) {
                $question = "File '{$file}' already exists. Do you want to replace it?";
            }
            return $this->confirm($question, $defaultValue);
        }
    }

    protected function generateFile($filePath, $content)
    {
        $fileExists = $this->hasFile($filePath);
        $shouldWrite = $this->checkShouldWrite($filePath);

        if (!$shouldWrite) {
            return;
        }

        $this->writeFile($filePath, $content);
        if ($fileExists) {
            $this->addModifiedFile($filePath);
        } else {
            $this->addGeneratedFile($filePath);
        }
    }

    public function copyFile($from, $to)
    {
        $fileExists = $this->hasFile($to);
        $shouldWrite = $this->checkShouldWrite($to);
        if ($shouldWrite) {
            $this->makeDirectoryIfNotExists($to);
            copy($from, base_path($to));
            if ($fileExists) {
                $this->addModifiedFile($to);
            } else {
                $this->addAddedFile($to);
            }
        }
    }

    public function writeFile($path, $content)
    {
        $path = ltrim($path, "/");
        $this->makeDirectoryIfNotExists($path);

        return file_put_contents(base_path($path), $content);
    }

    public function makeDirectoryIfNotExists($path)
    {
        $paths = explode("/", $path);
        $filename = array_pop($paths);
        $dir = [];

        // Make directories if not exists
        while($dir[] = array_shift($paths)) {
            $directory = base_path(implode('/', $dir));
            if (!is_dir($directory)) {
                mkdir($directory);
            }
        }
    }

    public function appendFile($path, $content)
    {
        return file_put_contents(base_path($path), $content, FILE_APPEND);
    }

    public function hasFile($path)
    {
        return file_exists(base_path($path));
    }

    public function getFileContent($path)
    {
        return file_get_contents(base_path($path));
    }

    public function addGeneratedFile($file, $info = true)
    {
        $this->generatedFiles[] = $file;
        if ($info AND !$this->option('silent')) {
            $this->info("- [generate] {$file}");
        }
    }

    public function addAddedFile($file, $info = true)
    {
        $this->addedFiles[] = $file;
        if ($info AND !$this->option('silent')) {
            $this->info("- [added] {$file}");
        }
    }

    public function addModifiedFile($file, $info = true)
    {
        $this->modifiedFiles[] = $file;
        if ($info AND !$this->option('silent')) {
            $this->info("- [overwrite] {$file}");
        }
    }

    public function getAddedFiles()
    {
        return $this->addedFiles;
    }

    public function getModifiedFiles()
    {
        return $this->modifiedFiles;
    }

}
