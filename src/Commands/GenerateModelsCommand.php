<?php

namespace AmranIbrahem\ModelGenerator\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Exception;

class GenerateModelsCommand extends Command
{
    protected $signature = 'models:generate
                            {--tables= : Specific tables to generate (comma separated)}
                            {--path= : Models directory path}
                            {--namespace= : Models namespace}
                            {--relationships : Auto generate relationships}
                            {--force : Update existing models with missing properties}';

    protected $description = 'Generate Eloquent models from database tables';

    public function handle()
    {
        $this->info('🚀 Starting Model Generation...');

        try {
            $tables = $this->getTables();
            $path = $this->option('path') ?: app_path('Models');
            $namespace = $this->option('namespace') ?: 'App\Models';
            $generateRelationships = $this->option('relationships');
            $forceUpdate = $this->option('force');

            if (!File::exists($path)) {
                File::makeDirectory($path, 0755, true);
            }

            $generatedCount = 0;
            $updatedCount = 0;
            $completedCount = 0;

            foreach ($tables as $table) {
                $result = $this->generateModel($table, $path, $namespace, $generateRelationships, $forceUpdate);
                if ($result === 'generated') {
                    $generatedCount++;
                } elseif ($result === 'updated') {
                    $updatedCount++;
                } elseif ($result === 'completed') {
                    $completedCount++;
                }
            }

            $this->info("✅ Successfully generated {$generatedCount} models!");
            if ($updatedCount > 0) {
                $this->info("✅ Updated {$updatedCount} existing models with relationships!");
            }
            if ($completedCount > 0) {
                $this->info("✅ Completed {$completedCount} existing models with missing properties!");
            }
            $this->info("📁 Models location: {$path}");

        } catch (Exception $e) {
            $this->error('❌ Error during model generation: ' . $e->getMessage());
            return 1;
        }

        return 0;
    }

    protected function getTables()
    {
        $specificTables = $this->option('tables');

        if ($specificTables) {
            return explode(',', $specificTables);
        }

        $tables = DB::select('SHOW TABLES');
        $tableNames = [];

        foreach ($tables as $table) {
            $tableNames[] = $table->{'Tables_in_' . config('database.connections.mysql.database')};
        }

        return array_filter($tableNames, function ($table) {
            return !in_array($table, ['migrations', 'password_reset_tokens', 'failed_jobs', 'personal_access_tokens']);
        });
    }

    protected function generateModel($tableName, $path, $namespace, $generateRelationships, $forceUpdate)
    {
        try {
            $className = $this->getClassName($tableName);
            $modelPath = $path . '/' . $className . '.php';

            $fillable = $this->getFillableColumns($tableName);
            $casts = $this->getCasts($tableName);
            $relationships = $generateRelationships ? $this->getRelationships($tableName) : '';

            if (File::exists($modelPath)) {
                if ($forceUpdate) {
                    $result = $this->updateExistingModel($modelPath, $tableName, $fillable, $casts, $relationships);
                    if ($result === 'updated') {
                        $this->info("✅ Updated model with relationships: {$className}");
                        return 'updated';
                    } elseif ($result === 'completed') {
                        $this->info("✅ Completed model with missing properties: {$className}");
                        return 'completed';
                    } else {
                        $this->warn("⚠️ Model {$className} already complete, skipping...");
                        return 'skipped';
                    }
                } else {
                    $this->warn("⚠️ Model {$className} already exists, skipping...");
                    return 'skipped';
                }
            }

            $modelContent = $this->buildModelContent($className, $namespace, $tableName, $fillable, $casts, $relationships);

            if (File::put($modelPath, $modelContent) !== false) {
                $this->info("✅ Generated model: {$className}");
                return 'generated';
            }

            throw new Exception("Failed to write model file: {$modelPath}");

        } catch (Exception $e) {
            $this->warn("⚠️ Could not generate model for {$tableName}: " . $e->getMessage());
            return 'error';
        }
    }

    protected function updateExistingModel($modelPath, $tableName, $fillable, $casts, $relationships)
    {
        $content = File::get($modelPath);
        $originalContent = $content;
        $changesMade = false;

        if (!str_contains($content, 'protected $table')) {
            $tableProperty = "
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected \$table = '{$tableName}';";

            $classStart = strpos($content, '{') + 1;
            $content = substr($content, 0, $classStart) . $tableProperty . substr($content, $classStart);
            $changesMade = true;
        }

        if (!str_contains($content, 'protected $fillable')) {
            $fillableString = $this->arrayToString($fillable);
            $fillableProperty = "
    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected \$fillable = {$fillableString};";

            $tablePos = strpos($content, 'protected $table');
            if ($tablePos !== false) {
                $endOfTable = strpos($content, ';', $tablePos) + 1;
                $content = substr($content, 0, $endOfTable) . $fillableProperty . substr($content, $endOfTable);
            } else {
                $classStart = strpos($content, '{') + 1;
                $content = substr($content, 0, $classStart) . $fillableProperty . substr($content, $classStart);
            }
            $changesMade = true;
        }

        if (!str_contains($content, 'protected $casts') && !empty($casts)) {
            $castsString = $this->buildCastsProperty($casts);

            $fillablePos = strpos($content, 'protected $fillable');
            if ($fillablePos !== false) {
                $endOfFillable = strpos($content, ';', $fillablePos) + 1;
                $content = substr($content, 0, $endOfFillable) . $castsString . substr($content, $endOfFillable);
            } else {
                $classStart = strpos($content, '{') + 1;
                $content = substr($content, 0, $classStart) . $castsString . substr($content, $classStart);
            }
            $changesMade = true;
        }

        if ($relationships) {
            $relationshipMethods = $this->extractRelationshipMethods($relationships);

            foreach ($relationshipMethods as $method) {
                $methodName = $this->extractMethodName($method);
                if (!str_contains($content, "function {$methodName}()")) {
                    $lastBrace = strrpos($content, '}');
                    if ($lastBrace !== false) {
                        $content = substr($content, 0, $lastBrace) . $method . "\n" . substr($content, $lastBrace);
                        $changesMade = true;
                    }
                }
            }
        }

        if ($changesMade && $content !== $originalContent) {
            File::put($modelPath, $content);
            return $relationships ? 'updated' : 'completed';
        }

        return 'no_changes';
    }

    protected function extractRelationshipMethods($relationships)
    {
        preg_match_all('/    \/\*\*(.*?)\*\/\s*    public function \w+\(\)\s*    \{(.*?)\n    \}/s', $relationships, $matches);

        $methods = [];
        foreach ($matches[0] as $match) {
            $methods[] = $match;
        }

        return $methods;
    }

    protected function extractMethodName($method)
    {
        preg_match('/public function (\w+)\(\)/', $method, $matches);
        return $matches[1] ?? '';
    }

    protected function extractExistingMethodNames($relationships)
    {
        preg_match_all('/public function (\w+)\(\)/', $relationships, $matches);
        return $matches[1] ?? [];
    }

    protected function addRelationshipIfNotExists(&$relationships, $existingMethods, $methodName, $relationshipCode)
    {
        $methodNameLower = strtolower($methodName);
        $existingMethodsLower = array_map('strtolower', $existingMethods);

        if (!in_array($methodNameLower, $existingMethodsLower)) {
            $relationships .= $relationshipCode;
            $existingMethods[] = $methodName;
        }
    }

    protected function getClassName($tableName)
    {
        $singular = $this->getSingular($tableName);
        return str_replace(' ', '', ucwords(str_replace('_', ' ', $singular)));
    }

    protected function getSingular($tableName)
    {
        $irregular = [
            'people' => 'person',
            'children' => 'child',
            'men' => 'man',
            'women' => 'woman',
            'teeth' => 'tooth',
            'feet' => 'foot',
            'mice' => 'mouse',
            'geese' => 'goose',
        ];

        if (isset($irregular[$tableName])) {
            return $irregular[$tableName];
        }

        $patterns = [
            '/(.*)ies$/' => '$1y',
            '/(.*)ses$/' => '$1s',
            '/(.*)xes$/' => '$1x',
            '/(.*)ches$/' => '$1ch',
            '/(.*)shes$/' => '$1sh',
            '/(.*)uses$/' => '$1us',
            '/(.*)sses$/' => '$1ss',
            '/(.*)s$/' => '$1',
        ];

        foreach ($patterns as $pattern => $replacement) {
            if (preg_match($pattern, $tableName)) {
                return preg_replace($pattern, $replacement, $tableName);
            }
        }

        return $tableName;
    }

    protected function getFillableColumns($tableName)
    {
        $columns = DB::select("SHOW COLUMNS FROM {$tableName}");
        $fillable = [];

        foreach ($columns as $column) {
            $columnName = $column->Field;

            if (!in_array($columnName, ['id', 'created_at', 'updated_at', 'deleted_at', 'remember_token'])) {
                $fillable[] = $columnName;
            }
        }

        return $fillable;
    }

    protected function getCasts($tableName)
    {
        $columns = DB::select("SHOW COLUMNS FROM {$tableName}");
        $casts = [];

        foreach ($columns as $column) {
            $columnName = $column->Field;
            $type = $column->Type;

            if (str_contains($type, 'timestamp') || str_contains($type, 'datetime')) {
                $casts[$columnName] = 'datetime';
            } elseif (str_contains($type, 'date')) {
                $casts[$columnName] = 'date';
            } elseif (str_contains($type, 'json')) {
                $casts[$columnName] = 'array';
            } elseif (str_contains($type, 'tinyint(1)')) {
                $casts[$columnName] = 'boolean';
            } elseif (str_contains($type, 'int') && $columnName !== 'id') {
                $casts[$columnName] = 'integer';
            } elseif (str_contains($type, 'decimal') || str_contains($type, 'float') || str_contains($type, 'double')) {
                $casts[$columnName] = 'float';
            }
        }

        return $casts;
    }

    protected function getRelationships($tableName)
    {
        $relationships = '';
        $existingMethods = [];

        try {
            $foreignKeys = DB::select("
                SELECT
                    TABLE_NAME,
                    COLUMN_NAME,
                    REFERENCED_TABLE_NAME,
                    REFERENCED_COLUMN_NAME
                FROM
                    INFORMATION_SCHEMA.KEY_COLUMN_USAGE
                WHERE
                    REFERENCED_TABLE_NAME IS NOT NULL
                    AND TABLE_SCHEMA = ?
                    AND TABLE_NAME = ?
            ", [config('database.connections.mysql.database'), $tableName]);

            foreach ($foreignKeys as $fk) {
                $relatedModel = $this->getClassName($fk->REFERENCED_TABLE_NAME);
                $relationshipName = $this->getRelationshipName($fk->COLUMN_NAME, $relatedModel);

                if (!$this->relationshipExistsCaseInsensitive($relationships, $relationshipName)) {
                    $relationships .= "\n    /**\n     * Get the {$relatedModel} that owns the {$this->getClassName($tableName)}.\n     */\n    public function {$relationshipName}()\n    {\n        return \$this->belongsTo({$relatedModel}::class, '{$fk->COLUMN_NAME}', '{$fk->REFERENCED_COLUMN_NAME}');\n    }";
                    $existingMethods[] = $relationshipName;
                }
            }

            $referencingTables = DB::select("
                SELECT
                    TABLE_NAME,
                    COLUMN_NAME
                FROM
                    INFORMATION_SCHEMA.KEY_COLUMN_USAGE
                WHERE
                    REFERENCED_TABLE_NAME = ?
                    AND TABLE_SCHEMA = ?
            ", [$tableName, config('database.connections.mysql.database')]);

            foreach ($referencingTables as $ref) {
                $relatedModel = $this->getClassName($ref->TABLE_NAME);
                $relationshipName = $this->getPlural($relatedModel);

                if (!$this->relationshipExistsCaseInsensitive($relationships, $relationshipName)) {
                    $relationships .= "\n    /**\n     * Get all the {$relatedModel} for the {$this->getClassName($tableName)}.\n     */\n    public function {$relationshipName}()\n    {\n        return \$this->hasMany({$relatedModel}::class, '{$ref->COLUMN_NAME}', 'id');\n    }";
                    $existingMethods[] = $relationshipName;
                }
            }

            $this->addSpecialRelationships($tableName, $relationships, $existingMethods);

        } catch (Exception $e) {
            $this->warn("⚠️ Could not generate relationships for {$tableName}: " . $e->getMessage());
        }

        return $relationships;
    }

    protected function addSpecialRelationships($tableName, &$relationships, $existingMethods = [])
    {
        if (empty($existingMethods)) {
            $existingMethods = $this->extractExistingMethodNames($relationships);
        }

        switch ($tableName) {
            case 'users':
                $this->addRelationshipIfNotExists($relationships, $existingMethods, 'favorites', "
    /**
     * Get all the Favorite for the User.
     */
    public function favorites()
    {
        return \$this->hasMany(Favorite::class, 'user_id', 'id');
    }");

                $this->addRelationshipIfNotExists($relationships, $existingMethods, 'profiles', "
    /**
     * Get all the Profile for the User.
     */
    public function profiles()
    {
        return \$this->hasMany(Profile::class, 'user_id', 'id');
    }");
                break;

            case 'products':
                $this->addRelationshipIfNotExists($relationships, $existingMethods, 'favorites', "
    /**
     * Get all the Favorite for the Product.
     */
    public function favorites()
    {
        return \$this->hasMany(Favorite::class, 'product_id', 'id');
    }");
                break;
        }
    }

    protected function relationshipExistsCaseInsensitive($relationships, $relationshipName)
    {
        $pattern = '/public function\s+(' . preg_quote($relationshipName, '/') . ')\s*\(\)/i';
        return preg_match($pattern, $relationships);
    }

    protected function relationshipExists($relationships, $relationshipName)
    {
        return str_contains($relationships, "function {$relationshipName}()");
    }

    protected function getRelationshipName($columnName, $relatedModel)
    {
        $cleanName = preg_replace('/_id$/', '', $columnName);
        $cleanName = preg_replace('/_uuid$/', '', $cleanName);

        return $cleanName;
    }

    protected function getPlural($singular)
    {
        $irregular = [
            'person' => 'people',
            'child' => 'children',
            'man' => 'men',
            'woman' => 'women',
            'tooth' => 'teeth',
            'foot' => 'feet',
            'mouse' => 'mice',
            'goose' => 'geese',
        ];

        if (isset($irregular[$singular])) {
            return $irregular[$singular];
        }

        $patterns = [
            '/(.*)y$/' => '$1ies',
            '/(.*)s$/' => '$1ses',
            '/(.*)x$/' => '$1xes',
            '/(.*)ch$/' => '$1ches',
            '/(.*)sh$/' => '$1shes',
            '/(.*)us$/' => '$1uses',
            '/(.*)ss$/' => '$1sses',
            '/(.*)$/' => '$1s',
        ];

        foreach ($patterns as $pattern => $replacement) {
            if (preg_match($pattern, $singular)) {
                return preg_replace($pattern, $replacement, $singular);
            }
        }

        return $singular . 's';
    }

    protected function buildModelContent($className, $namespace, $tableName, $fillable, $casts, $relationships)
    {
        $fillableString = $this->arrayToString($fillable);
        $castsString = !empty($casts) ? $this->buildCastsProperty($casts) : '';

        return "<?php

namespace {$namespace};

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class {$className} extends Model
{
    use HasFactory;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected \$table = '{$tableName}';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected \$fillable = {$fillableString};
{$castsString}{$relationships}
}";
    }

    protected function buildCastsProperty($casts)
    {
        $castsString = $this->arrayToString($casts, true);

        return "
    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected \$casts = {$castsString};
";
    }

    protected function arrayToString($array, $isAssociative = false)
    {
        if (empty($array)) {
            return $isAssociative ? "[]" : "[]";
        }

        $items = [];
        foreach ($array as $key => $value) {
            if ($isAssociative) {
                $items[] = "        '{$key}' => '{$value}'";
            } else {
                $items[] = "        '{$value}'";
            }
        }

        $content = implode(",\n", $items);
        return "[\n{$content}\n    ]";
    }
}
