<?php

use Aldoggutierrez\LaravelSchemaManager\Tests\TestCase;

uses(TestCase::class)->in(__DIR__);

function modelWithTable(string $className, string $tableValue): string
{
    return <<<PHP
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class {$className} extends Model
{
    use HasFactory;

    protected \$table = '{$tableValue}';

    protected \$fillable = ['name'];
}
PHP;
}

function modelWithoutTable(string $className): string
{
    return <<<PHP
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class {$className} extends Model
{
    use HasFactory;

    protected \$fillable = ['name'];
}
PHP;
}
