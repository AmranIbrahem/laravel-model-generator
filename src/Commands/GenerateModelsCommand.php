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
                            {--relationships : Auto generate relationships}';

    protected $description = 'Generate Eloquent models from database tables';

    public function handle()
    {
        $this->info('🚀 Starting Model Generation...');

        try {
            $tables = $this->getTables();
            $path = $this->option('path') ?: app_path('Models');
            $namespace = $this->option('namespace') ?: 'App\Models';
            $generateRelationships = $this->option('relationships');

            if (!File::exists($path)) {
                File::makeDirectory($path, 0755, true);
            }

            $generatedCount = 0;

            foreach ($tables as $table) {
                if ($this->generateModel($table, $path, $namespace, $generateRelationships)) {
                    $generatedCount++;
                }
            }

            $this->info("✅ Successfully generated {$generatedCount} models!");
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

    protected function generateModel($tableName, $path, $namespace, $generateRelationships)
    {
        try {
            $className = $this->getClassName($tableName);
            $modelPath = $path . '/' . $className . '.php';

            if (File::exists($modelPath)) {
                $this->warn("⚠️ Model {$className} already exists, skipping...");
                return false;
            }

            $fillable = $this->getFillableColumns($tableName);
            $casts = $this->getCasts($tableName);
            $relationships = $generateRelationships ? $this->getRelationships($tableName) : '';

            $modelContent = $this->buildModelContent($className, $namespace, $tableName, $fillable, $casts, $relationships);

            if (File::put($modelPath, $modelContent) !== false) {
                $this->info("✅ Generated model: {$className}");
                return true;
            }

            throw new Exception("Failed to write model file: {$modelPath}");

        } catch (Exception $e) {
            $this->warn("⚠️ Could not generate model for {$tableName}: " . $e->getMessage());
            return false;
        }
    }

    protected function getClassName($tableName)
    {
        return str_replace(' ', '', ucwords(str_replace('_', ' ', $tableName)));
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
                $relationshipName = strtolower($relatedModel);

                // belongsTo relationship
                $relationships .= "\n    /**\n     * Get the {$relatedModel} that owns the {$this->getClassName($tableName)}.\n     */\n    public function {$relationshipName}()\n    {\n        return \$this->belongsTo({$relatedModel}::class, '{$fk->COLUMN_NAME}', '{$fk->REFERENCED_COLUMN_NAME}');\n    }";
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
                $relationshipName = strtolower(str_replace('_', '', $ref->TABLE_NAME));

                $relationships .= "\n    /**\n     * Get all the {$relatedModel} for the {$this->getClassName($tableName)}.\n     */\n    public function {$relationshipName}()\n    {\n        return \$this->hasMany({$relatedModel}::class, '{$ref->COLUMN_NAME}', 'id');\n    }";
            }

        } catch (Exception $e) {
            $this->warn("⚠️ Could not generate relationships for {$tableName}: " . $e->getMessage());
        }

        return $relationships;
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
