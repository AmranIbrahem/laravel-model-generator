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

        $connection = config('database.default');
        $database = config("database.connections.{$connection}.database");

        switch ($connection) {
            case 'pgsql':
                return $this->getPostgresTables($database);
            case 'sqlite':
                return $this->getSqliteTables();
            case 'mysql':
            default:
                return $this->getMysqlTables($database);
        }
    }

    protected function getMysqlTables($database)
    {
        $tables = DB::select('SHOW TABLES');
        $tableNames = [];

        foreach ($tables as $table) {
            $tableNames[] = $table->{'Tables_in_' . $database};
        }

        return array_filter($tableNames, function ($table) {
            return !in_array($table, ['migrations', 'password_reset_tokens', 'failed_jobs', 'personal_access_tokens']);
        });
    }

    protected function getPostgresTables($database)
    {
        $tables = DB::select("
            SELECT table_name
            FROM information_schema.tables
            WHERE table_schema = 'public'
            AND table_type = 'BASE TABLE'
        ");

        $tableNames = [];
        foreach ($tables as $table) {
            $tableNames[] = $table->table_name;
        }

        return array_filter($tableNames, function ($table) {
            return !in_array($table, ['migrations']);
        });
    }

    protected function getSqliteTables()
    {
        $tables = DB::select("
            SELECT name as table_name
            FROM sqlite_master
            WHERE type = 'table'
            AND name != 'sqlite_sequence'
        ");

        $tableNames = [];
        foreach ($tables as $table) {
            $tableNames[] = $table->table_name;
        }

        return array_filter($tableNames, function ($table) {
            return !in_array($table, ['migrations']);
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
            $phpDoc = $this->generatePhpDoc($tableName, $generateRelationships);

            if (File::exists($modelPath)) {
                if ($forceUpdate) {
                    $result = $this->updateExistingModel($modelPath, $tableName, $fillable, $casts, $relationships, $phpDoc);
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

            $modelContent = $this->buildModelContent($className, $namespace, $tableName, $fillable, $casts, $relationships, $phpDoc);

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

    protected function updateExistingModel($modelPath, $tableName, $fillable, $casts, $relationships, $phpDoc)
    {
        $content = File::get($modelPath);
        $originalContent = $content;
        $changesMade = false;

        if (!str_contains($content, '/**') && $phpDoc) {
            $classPos = strpos($content, 'class');
            if ($classPos !== false) {
                $content = substr($content, 0, $classPos) . $phpDoc . "\n" . substr($content, $classPos);
                $changesMade = true;
            }
        }

        if (!str_contains($content, 'protected $table')) {
            $tableProperty = "
    protected \$table = '{$tableName}';";

            $classStart = strpos($content, '{') + 1;
            $content = substr($content, 0, $classStart) . $tableProperty . substr($content, $classStart);
            $changesMade = true;
        }

        if (!str_contains($content, 'protected $fillable')) {
            $fillableString = $this->arrayToString($fillable);
            $fillableProperty = "
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
                if (!$this->methodExistsCaseInsensitive($content, $methodName)) {
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
        preg_match_all('/    public function \w+\(\)\s*    \{(.*?)\n    \}/s', $relationships, $matches);

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

    protected function methodExistsCaseInsensitive($content, $methodName)
    {
        $pattern = '/public\s+function\s+(' . preg_quote($methodName, '/') . ')\s*\(\)/i';
        return preg_match($pattern, $content);
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
        $connection = config('database.default');

        switch ($connection) {
            case 'pgsql':
                return $this->getPostgresColumns($tableName);
            case 'sqlite':
                return $this->getSqliteColumns($tableName);
            case 'mysql':
            default:
                return $this->getMysqlColumns($tableName);
        }
    }

    protected function getMysqlColumns($tableName)
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

    protected function getPostgresColumns($tableName)
    {
        $columns = DB::select("
            SELECT column_name as field, data_type, is_nullable
            FROM information_schema.columns
            WHERE table_name = ?
            ORDER BY ordinal_position
        ", [$tableName]);

        $fillable = [];
        foreach ($columns as $column) {
            $columnName = $column->field;
            if (!in_array($columnName, ['id', 'created_at', 'updated_at', 'deleted_at', 'remember_token'])) {
                $fillable[] = $columnName;
            }
        }

        return $fillable;
    }

    protected function getSqliteColumns($tableName)
    {
        $columns = DB::select("PRAGMA table_info({$tableName})");
        $fillable = [];

        foreach ($columns as $column) {
            $columnName = $column->name;
            if (!in_array($columnName, ['id', 'created_at', 'updated_at', 'deleted_at', 'remember_token'])) {
                $fillable[] = $columnName;
            }
        }

        return $fillable;
    }

    protected function getCasts($tableName)
    {
        $connection = config('database.default');

        switch ($connection) {
            case 'pgsql':
                return $this->getPostgresCasts($tableName);
            case 'sqlite':
                return $this->getSqliteCasts($tableName);
            case 'mysql':
            default:
                return $this->getMysqlCasts($tableName);
        }
    }

    protected function getMysqlCasts($tableName)
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

    protected function getPostgresCasts($tableName)
    {
        $columns = DB::select("
            SELECT column_name as field, data_type, is_nullable
            FROM information_schema.columns
            WHERE table_name = ?
            ORDER BY ordinal_position
        ", [$tableName]);

        $casts = [];
        foreach ($columns as $column) {
            $columnName = $column->field;
            $type = $column->data_type;

            if (str_contains($type, 'timestamp') || str_contains($type, 'datetime')) {
                $casts[$columnName] = 'datetime';
            } elseif (str_contains($type, 'date')) {
                $casts[$columnName] = 'date';
            } elseif (str_contains($type, 'json')) {
                $casts[$columnName] = 'array';
            } elseif (str_contains($type, 'boolean')) {
                $casts[$columnName] = 'boolean';
            } elseif (str_contains($type, 'integer') || str_contains($type, 'bigint') || str_contains($type, 'smallint')) {
                $casts[$columnName] = 'integer';
            } elseif (str_contains($type, 'numeric') || str_contains($type, 'real') || str_contains($type, 'double precision')) {
                $casts[$columnName] = 'float';
            }
        }

        return $casts;
    }

    protected function getSqliteCasts($tableName)
    {
        $columns = DB::select("PRAGMA table_info({$tableName})");
        $casts = [];

        foreach ($columns as $column) {
            $columnName = $column->name;
            $type = strtolower($column->type);

            if (str_contains($type, 'timestamp') || str_contains($type, 'datetime')) {
                $casts[$columnName] = 'datetime';
            } elseif (str_contains($type, 'date')) {
                $casts[$columnName] = 'date';
            } elseif (str_contains($type, 'json')) {
                $casts[$columnName] = 'array';
            } elseif (str_contains($type, 'boolean') || str_contains($type, 'bool')) {
                $casts[$columnName] = 'boolean';
            } elseif (str_contains($type, 'int') || str_contains($type, 'integer')) {
                $casts[$columnName] = 'integer';
            } elseif (str_contains($type, 'real') || str_contains($type, 'float') || str_contains($type, 'double') || str_contains($type, 'numeric')) {
                $casts[$columnName] = 'float';
            }
        }

        return $casts;
    }

    protected function generatePhpDoc($tableName, $generateRelationships)
    {
        $connection = config('database.default');

        switch ($connection) {
            case 'pgsql':
                $columns = DB::select("
                    SELECT column_name as Field, data_type as Type, is_nullable as Null
                    FROM information_schema.columns
                    WHERE table_name = ?
                    ORDER BY ordinal_position
                ", [$tableName]);
                break;
            case 'sqlite':
                $columns = DB::select("PRAGMA table_info({$tableName})");
                $columns = array_map(function($col) {
                    return (object)[
                        'Field' => $col->name,
                        'Type' => $col->type,
                        'Null' => $col->notnull ? 'NO' : 'YES'
                    ];
                }, $columns);
                break;
            case 'mysql':
            default:
                $columns = DB::select("SHOW COLUMNS FROM {$tableName}");
                break;
        }

        $phpDoc = "/**\n";

        foreach ($columns as $column) {
            $columnName = $column->Field;
            $type = $this->getPhpType($column->Type, $column->Null);
            $access = $this->getPropertyAccess($columnName);
            $phpDoc .= " * @property{$access} {$type} \${$columnName}\n";
        }

        if ($generateRelationships) {
            $relationships = $this->getRelationshipNames($tableName);
            foreach ($relationships as $relationship) {
                $phpDoc .= " * @property-read \\{$relationship['model']}[] \${$relationship['name']}\n";
            }
        }

        $phpDoc .= " */";

        return $phpDoc;
    }

    protected function getPhpType($mysqlType, $isNullable)
    {
        $nullable = $isNullable === 'YES' ? '|null' : '';

        if (str_contains($mysqlType, 'int')) {
            return 'int' . $nullable;
        } elseif (str_contains($mysqlType, 'decimal') || str_contains($mysqlType, 'float') || str_contains($mysqlType, 'double') || str_contains($mysqlType, 'numeric') || str_contains($mysqlType, 'real')) {
            return 'float' . $nullable;
        } elseif (str_contains($mysqlType, 'bool') || str_contains($mysqlType, 'tinyint(1)')) {
            return 'bool' . $nullable;
        } elseif (str_contains($mysqlType, 'timestamp') || str_contains($mysqlType, 'datetime')) {
            return '\\Carbon\\Carbon' . $nullable;
        } elseif (str_contains($mysqlType, 'date')) {
            return '\\Carbon\\Carbon' . $nullable;
        } elseif (str_contains($mysqlType, 'json')) {
            return 'array' . $nullable;
        } else {
            return 'string' . $nullable;
        }
    }

    protected function getPropertyAccess($columnName)
    {
        $readOnlyColumns = ['id', 'created_at', 'updated_at', 'deleted_at'];

        if (in_array($columnName, $readOnlyColumns)) {
            return '-read';
        }

        return '';
    }

    protected function getRelationshipNames($tableName)
    {
        $relationships = [];

        try {
            $foreignKeys = DB::select("
                SELECT COLUMN_NAME, REFERENCED_TABLE_NAME
                FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
                WHERE REFERENCED_TABLE_NAME IS NOT NULL
                AND TABLE_SCHEMA = ?
                AND TABLE_NAME = ?
            ", [config('database.connections.mysql.database'), $tableName]);

            foreach ($foreignKeys as $fk) {
                $relatedModel = $this->getClassName($fk->REFERENCED_TABLE_NAME);
                $relationshipName = $this->getBelongsToRelationshipName($fk->COLUMN_NAME);
                $relationships[] = [
                    'name' => $relationshipName,
                    'model' => 'App\\Models\\' . $relatedModel
                ];
            }

            $referencingTables = DB::select("
                SELECT TABLE_NAME, COLUMN_NAME
                FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
                WHERE REFERENCED_TABLE_NAME = ?
                AND TABLE_SCHEMA = ?
            ", [$tableName, config('database.connections.mysql.database')]);

            foreach ($referencingTables as $ref) {
                $relatedModel = $this->getClassName($ref->TABLE_NAME);
                $relationshipName = $this->getHasManyRelationshipName($ref->TABLE_NAME);
                $relationships[] = [
                    'name' => $relationshipName,
                    'model' => 'App\\Models\\' . $relatedModel
                ];
            }

            $manyToManyRelationships = $this->getManyToManyRelationships($tableName);
            foreach ($manyToManyRelationships as $relationship) {
                $relationships[] = [
                    'name' => $relationship['name'],
                    'model' => 'App\\Models\\' . $relationship['related_model']
                ];
            }

        } catch (Exception $e) {
        }

        return $relationships;
    }

    protected function getRelationships($tableName)
    {
        $relationships = '';

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
                $relationshipName = $this->getBelongsToRelationshipName($fk->COLUMN_NAME);

                if (!$this->relationshipExistsCaseInsensitive($relationships, $relationshipName)) {
                    $relationships .= "
    public function {$relationshipName}()
    {
        return \$this->belongsTo({$relatedModel}::class, '{$fk->COLUMN_NAME}', '{$fk->REFERENCED_COLUMN_NAME}');
    }";
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
                $relationshipName = $this->getHasManyRelationshipName($ref->TABLE_NAME);

                if (!$this->relationshipExistsCaseInsensitive($relationships, $relationshipName)) {
                    $relationships .= "
    public function {$relationshipName}()
    {
        return \$this->hasMany({$relatedModel}::class, '{$ref->COLUMN_NAME}', 'id');
    }";
                }
            }

            $manyToManyRelationships = $this->getManyToManyRelationships($tableName);
            foreach ($manyToManyRelationships as $relationship) {
                $relationships .= "
    public function {$relationship['name']}()
    {
        return \$this->belongsToMany({$relationship['related_model']}::class, '{$relationship['pivot_table']}'{$relationship['keys']});
    }";
            }

        } catch (Exception $e) {
            $this->warn("⚠️ Could not generate relationships for {$tableName}: " . $e->getMessage());
        }

        return $relationships;
    }

    protected function getManyToManyRelationships($tableName)
    {
        $relationships = [];

        try {
            $pivotTables = DB::select("
                SELECT TABLE_NAME
                FROM INFORMATION_SCHEMA.TABLES
                WHERE TABLE_SCHEMA = ?
                AND TABLE_NAME LIKE '%{$tableName}%'
                AND TABLE_NAME != '{$tableName}'
                AND (TABLE_NAME LIKE '%_%_%' OR TABLE_NAME REGEXP '.*[0-9].*')
            ", [config('database.connections.mysql.database')]);

            foreach ($pivotTables as $pivotTable) {
                $columns = DB::select("SHOW COLUMNS FROM {$pivotTable->TABLE_NAME}");
                $foreignKeys = [];

                foreach ($columns as $column) {
                    if (str_contains($column->Field, '_id')) {
                        $foreignKeys[] = $column->Field;
                    }
                }

                if (count($foreignKeys) === 2) {
                    $otherForeignKey = array_filter($foreignKeys, function($fk) use ($tableName) {
                        return !str_contains($fk, $tableName . '_id');
                    });

                    if (count($otherForeignKey) === 1) {
                        $otherKey = reset($otherForeignKey);
                        $relatedTable = str_replace('_id', '', $otherKey);
                        $relatedModel = $this->getClassName($relatedTable);

                        $relationships[] = [
                            'name' => $this->getPluralRelationshipName($relatedModel),
                            'related_model' => $relatedModel,
                            'pivot_table' => $pivotTable->TABLE_NAME,
                            'keys' => $this->getPivotKeys($tableName, $relatedTable, $foreignKeys)
                        ];
                    }
                }
            }

        } catch (Exception $e) {
        }

        return $relationships;
    }

    protected function getPivotKeys($tableName, $relatedTable, $foreignKeys)
    {
        $localKey = $tableName . '_id';
        $relatedKey = $relatedTable . '_id';

        if (in_array($localKey, $foreignKeys) && in_array($relatedKey, $foreignKeys)) {
            return ", '{$localKey}', '{$relatedKey}'";
        }

        return '';
    }

    protected function getPluralRelationshipName($singular)
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
            return lcfirst($irregular[$singular]);
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
                return lcfirst(preg_replace($pattern, $replacement, $singular));
            }
        }

        return lcfirst($singular . 's');
    }

    protected function relationshipExistsCaseInsensitive($relationships, $relationshipName)
    {
        $pattern = '/public\s+function\s+(' . preg_quote($relationshipName, '/') . ')\s*\(\)/i';
        return preg_match($pattern, $relationships);
    }

    protected function getBelongsToRelationshipName($columnName)
    {
        $relationshipName = preg_replace('/_id$/', '', $columnName);
        $relationshipName = preg_replace('/_uuid$/', '', $relationshipName);

        return $this->snakeToCamel($relationshipName);
    }

    protected function getHasManyRelationshipName($relatedTable)
    {
        $relationshipName = $relatedTable;

        return $this->snakeToCamel($relationshipName);
    }

    protected function snakeToCamel($string)
    {
        $string = str_replace('_', ' ', $string);
        $string = ucwords($string);
        $string = str_replace(' ', '', $string);

        return lcfirst($string);
    }

    protected function buildModelContent($className, $namespace, $tableName, $fillable, $casts, $relationships, $phpDoc)
    {
        $fillableString = $this->arrayToString($fillable);
        $castsString = !empty($casts) ? $this->buildCastsProperty($casts) : '';

        return "<?php

namespace {$namespace};

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

{$phpDoc}
class {$className} extends Model
{
    use HasFactory;

    protected \$table = '{$tableName}';

    protected \$fillable = {$fillableString};
{$castsString}{$relationships}
}";
    }

    protected function buildCastsProperty($casts)
    {
        $castsString = $this->arrayToString($casts, true);

        return "
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
