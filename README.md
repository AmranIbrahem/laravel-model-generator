# Laravel Model Generator

🚀 **Generate Eloquent Models Automatically from Database Tables**

[![Latest Version](https://img.shields.io/packagist/v/amranibrahem/laravel-model-generator.svg)](https://packagist.org/packages/amranibrahem/laravel-model-generator)
[![Total Downloads](https://img.shields.io/packagist/dt/amranibrahem/laravel-model-generator.svg)](https://packagist.org/packages/amranibrahem/laravel-model-generator)
[![License](https://img.shields.io/packagist/l/amranibrahem/laravel-model-generator.svg)](https://packagist.org/packages/amranibrahem/laravel-model-generator)
[![PHP Version](https://img.shields.io/packagist/php-v/amranibrahem/laravel-model-generator.svg)](https://packagist.org/packages/amranibrahem/laravel-model-generator)

A powerful Laravel package that automatically generates Eloquent models from your database tables with professional PHPDoc, relationships, and casting.

## ✨ Features

- ✅ **Auto-generate Eloquent models** from database tables
- ✅ **Professional PHPDoc** with type hints and relationships
- ✅ **Automatic relationship detection** (hasMany, belongsTo, belongsToMany)
- ✅ **Multi-database support** (MySQL, PostgreSQL, SQLite)
- ✅ **Smart model updating** without overwriting custom code
- ✅ **Custom namespaces and paths**
- ✅ **Beautiful console output** with emojis
- ✅ **Fillable properties & casting** generation
- ✅ **Table name singularization**
- ✅ **Many-to-Many relationships** detection

## 🚀 Installation

You can install the package via Composer:

```bash
composer require amranibrahem/laravel-model-generator
```
## 📖 **Usage**

**1- Generate All Models**
```bash
php artisan models:generate
```

**2- Generate Specific Tables**
```bash
php artisan models:generate --tables=users,posts,comments
```

**3- Generate with Relationships**
```bash
php artisan models:generate --relationships
```

**4- Update Existing Models**
```bash
php artisan models:generate --force --relationships
```

**5- Custom Path and Namespace**
```bash
php artisan models:generate --path=app/Domain --namespace=App\\Domain
```

**6- Combine Options**
```bash
php artisan models:generate --tables=users,posts --relationships --force --path=app/Models
```

## 🎯 Examples
**Generated Model Example**
```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property string $password
 * @property \Carbon\Carbon|null $created_at
 * @property \Carbon\Carbon|null $updated_at
 * @property-read \App\Models\Comment[] $comments
 * @property-read \App\Models\Post[] $posts
 */
class User extends Model
{
    use HasFactory;

    protected $table = 'users';

    protected $fillable = [
        'name',
        'email',
        'password'
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime'
    ];

    /**
     * Get all the Comment for the User.
     */
    public function comments()
    {
        return $this->hasMany(Comment::class, 'user_id', 'id');
    }

    /**
     * Get all the Post for the User.
     */
    public function posts()
    {
        return $this->hasMany(Post::class, 'user_id', 'id');
    }
}
```

**Many-to-Many Relationship Example**
```php
/**
* @property \App\Models\Tag[] $tags
  */
 class Post extends Model
{
  public function tags()
  {
  return $this->belongsToMany(Tag::class, 'post_tag', 'post_id', 'tag_id');
  }
}
```

## ⚙️ Options

| Option | Description | Default |
|--------|-------------|---------|
| `--tables` | Specific tables to generate (comma separated) | All tables |
| `--path` | Models directory path | `app/Models` |
| `--namespace` | Models namespace | `App\Models` |
| `--relationships` | Auto generate relationships | `false` |
| `--force` | Update existing models with missing properties | `false` |

## 🔧 Supported Databases

- **MySQL** - Full support with foreign key detection
- **PostgreSQL** - Complete support with schema information
- **SQLite** - Basic support with table info

## 🎨 Console Output

```bash
🚀 Starting Model Generation...
✅ Generated model: User
✅ Updated model with relationships: Post
⚠️ Model Comment already exists, skipping...
✅ Successfully generated 5 models!
✅ Updated 2 existing models with relationships!
📁 Models location: /path/to/app/Models
```
## ⚡ Comparison with Alternatives

| Feature | This Package | krlove/eloquent-model-generator | reliese/laravel |
|---------|--------------|----------------------------------|-----------------|
| Beautiful UI | ✅ | ❌ | ❌ |
| Model Updates | ✅ | ❌ | ❌ |
| Multi-DB Support | ✅ | ✅ | ✅ |
| Relationships | ✅ | ✅ | ✅ |
| PHPDoc Generation | ✅ | ✅ | ✅ |
| Zero Configuration | ✅ | ❌ | ❌ |
| Many-to-Many Detection | ✅ | ❌ | ✅ |

## 🤝 Contributing

Please see [CONTRIBUTING](CONTRIBUTING.md) for details. We welcome all contributions!

## 📝 Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## 🐛 Reporting Issues

If you discover any issues, please use the [GitHub issue tracker](https://github.com/amranibrahem/laravel-model-generator/issues).

## 🏆 Credits

- [Amran iBrahem](https://github.com/amranibrahem)


## 💡 Why Use This Package?

- **Save Development Time** - Generate models in seconds instead of hours
- **Reduce Errors** - Automatic relationship detection prevents mistakes
- **Professional Code** - PHPDoc and proper Laravel conventions
- **Flexible** - Works with existing projects and new ones
- **Safe Updates** - `--force` option only adds missing properties
- **Multi-Database** - Works with MySQL, PostgreSQL, and SQLite

## 🔗 Links

- [GitHub Repository](https://github.com/amranibrahem/laravel-model-generator)
- [Packagist](https://packagist.org/packages/amranibrahem/laravel-model-generator)
- [Issue Tracker](https://github.com/amranibrahem/laravel-model-generator/issues)

---

**⭐ Star us on GitHub if this package helped you!**

**🚀 Happy coding!**
