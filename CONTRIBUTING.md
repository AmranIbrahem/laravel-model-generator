# Contributing to Laravel Model Generator

Thank you for considering contributing to Laravel Model Generator! We welcome all contributions from the community.

## Code of Conduct

Please be respectful and considerate of others when contributing to this project.

## How to Contribute

### Reporting Bugs
1. Use the [GitHub issue tracker](https://github.com/amranibrahem/laravel-model-generator/issues)
2. Describe the bug in detail with steps to reproduce
3. Include your environment details (PHP version, Laravel version, database type)
4. Add relevant error messages or screenshots

### Suggesting Features
1. Check if the feature already exists or has been requested
2. Explain the use case clearly and how it benefits users
3. Provide examples of how the feature would work

### Pull Requests
1. Fork the repository
2. Create a feature branch (`git checkout -b feature/amazing-feature`)
3. Commit your changes (`git commit -m 'Add amazing feature'`)
4. Push to the branch (`git push origin feature/amazing-feature`)
5. Open a Pull Request

## Development Setup

### Prerequisites
- PHP 8.0 or higher
- Composer
- Laravel 9.0 or higher

### Local Development
1. Clone the repository:
```bash
    git clone https://github.com/amranibrahem/laravel-model-generator.git
    cd laravel-model-generator
```
2. Install dependencies:
```bash
   composer install
```
3. Test with a Laravel project:
```bash
    # Link the package for local development
    composer config repositories.local '{"type": "path", "url": "/path/to/laravel-model-generator"}' --file composer.json
    composer require amranibrahem/laravel-model-generator:@dev
```

## Coding Standards

* Follow PSR-12 coding standards
* Write clear PHPDoc comments for all methods
* Add type hints where possible
* Write tests for new features
* Update documentation accordingly

## Testing
```bash
    # Run tests
    composer test
    
    # Run tests with coverage
    composer test-coverage
```

## Database Support

When adding support for new databases:
* Test with multiple database types
* Update documentation
* Add to the supported databases list

## Documentation

* Update README.md for new features
* Add examples for complex functionality
* Keep CHANGELOG.md updated

## Questions?

Feel free to contact me at : amranibrahem2001@gmail.com
