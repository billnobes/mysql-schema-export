## v1.0.0 - Initial Release

MySQL/MariaDB schema export utility with PHP and Python implementations. Designed to export database schemas in JSON format optimized for AI coding assistants.

### Features

- **Dual Implementation**: PHP and Python versions with identical functionality
- **Complete Schema Export**: Tables, columns, constraints, indexes, foreign keys, and DDL statements
- **Multiple Configuration Methods**: CLI arguments, environment variables, and INI config files
- **Table Filtering**: Regex-based table name filtering with cross-language compatibility
- **Professional CLI**: Help system, argument parsing, and detailed error handling

### What's Included

- `export-schema.php` - PHP implementation (requires PDO MySQL)
- `export-schema.py` - Python implementation (requires PyMySQL)
- `config.example.ini` - Configuration template
- `README.md` - Documentation and usage examples
- `composer.json` - PHP package definition
- `requirements.txt` - Python dependencies
- `LICENSE` - MIT license

### Requirements

- **PHP**: 7.4+ with PDO MySQL extension
- **Python**: 3.6+ with PyMySQL package
- **Database**: MySQL 5.7+ or MariaDB 10.2+

### Basic Usage

```bash
# Using config file (recommended)
cp config.example.ini config.ini
php export-schema.php

# Command line arguments
php export-schema.php --database mydb --user root --password secret
python export-schema.py --database mydb --user root --password secret

# With table filtering
php export-schema.php --filter '/user_.*/'
python export-schema.py --filter 'user_.*'
```

### Output Format

Exports JSON containing database metadata with table structures, column definitions, relationships, and DDL statements. Designed specifically for providing context to AI coding assistants.