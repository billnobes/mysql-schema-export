# MySQL Schema Export Utility

A comprehensive PHP utility for exporting MySQL/MariaDB database schemas to JSON format. Exports complete database metadata including tables, columns, constraints, indexes, foreign keys, and DDL statements.

## Features

- 🗄️ **Complete Schema Export**: Tables, columns, constraints, indexes, foreign keys
- 🔍 **Table Filtering**: Regex-based table name filtering
- 🚀 **Multiple Configuration Methods**: CLI arguments, environment variables, or direct editing
- 📊 **Rich Metadata**: Includes row counts, storage engines, collations, and full DDL
- 🔧 **Developer Friendly**: Single file, no dependencies, easy to customize

## Installation

```bash
git clone https://github.com/billnobes/mysql-schema-export.git
cd mysql-schema-export
```

### PHP Version
No additional dependencies required - uses built-in PDO MySQL extension.

### Python Version
Install Python dependencies:
```bash
pip install -r requirements.txt
```

## Usage

### Command Line Interface

**PHP Version:**
```bash
# Basic usage
php export-schema.php --database mydb --user root --password secret

# With table filtering (only tables starting with 'user_')
php export-schema.php --db mydb --user root --filter '/user_.*/'

# Custom output directory
php export-schema.php --db mydb --user root --output /path/to/exports

# Show all options
php export-schema.php --help
```

**Python Version:**
```bash
# Basic usage
python export-schema.py --database mydb --user root --password secret

# With table filtering (only tables starting with 'user_')
python export-schema.py --db mydb --user root --filter 'user_.*'

# Custom output directory
python export-schema.py --db mydb --user root --output /path/to/exports

# Show all options
python export-schema.py --help
```

### Environment Variables

```bash
# Set environment variables
export DB_HOST=localhost
export DB_NAME=mydb
export DB_USER=root
export DB_PASS=secret
export TABLE_NAME_REGEXP='user_.*'  # PHP: '/user_.*/', Python: 'user_.*'

# Run the export (PHP or Python)
php export-schema.php
python export-schema.py
```

### Configuration Options

| Option | CLI Argument | Environment Variable | Default | Description |
|--------|--------------|---------------------|---------|-------------|
| Database Host | `--host` | `DB_HOST` | `localhost` | MySQL server hostname |
| Database Name | `--database` or `--db` | `DB_NAME` | *required* | Database to export |
| Username | `--user` | `DB_USER` | *required* | Database username |
| Password | `--password` | `DB_PASS` | `` | Database password |
| Port | `--port` | `DB_PORT` | `3306` | MySQL server port |
| Output Directory | `--output` | `OUTPUT_DIR` | `./export` | Where to save JSON files |
| Table Filter | `--filter` | `TABLE_NAME_REGEXP` | `/.*/ ` (PHP) `.*` (Python) | Regex pattern for table names |
| Schema Version | n/a | `SCHEMA_VERSION` | Current date | Version identifier |

## Table Filtering Examples

**PHP Version (uses regex delimiters):**
```bash
# Export all tables (default)
--filter '/.*/'/

# Export tables starting with 'user_'
--filter '/user_.*/'

# Export tables ending with '_log'
--filter '/.*_log$/'

# Export specific tables
--filter '/(users|orders|products)$/'
```

**Python Version (standard regex):**
```bash
# Export all tables (default)
--filter '.*'

# Export tables starting with 'user_'
--filter 'user_.*'

# Export tables ending with '_log'
--filter '.*_log$'

# Export specific tables
--filter '(users|orders|products)$'
```

## Output Format

The utility generates a JSON file with the following structure:

```json
{
  "database": "mydb",
  "generated_at": "2025-01-15T10:30:00-05:00",
  "schema_version": "2025-01-15",
  "tables": [
    {
      "name": "users",
      "columns": [
        {
          "name": "id",
          "type": "int(11)",
          "nullable": false,
          "default": null,
          "extra": "auto_increment",
          "comment": "User ID",
          "position": 1
        }
      ],
      "primary_key": ["id"],
      "unique": [],
      "indexes": [],
      "foreign_keys": [],
      "table_info": {
        "row_count_est": 1250,
        "engine": "InnoDB",
        "collation": "utf8mb4_general_ci"
      },
      "ddl": "CREATE TABLE `users` (...)"
    }
  ]
}
```

## Requirements

**PHP Version:**
- PHP 7.4 or higher
- PDO MySQL extension

**Python Version:**
- Python 3.6 or higher
- PyMySQL package

**Database:**
- MySQL 5.7+ or MariaDB 10.2+

## Use Cases

- **Documentation**: Generate schema documentation
- **Migration Planning**: Analyze database structure before migrations  
- **Backup**: Create schema snapshots for version control
- **Analysis**: Compare schemas between environments
- **Code Generation**: Generate models/classes from schema

## Contributing

1. Fork the repository
2. Create a feature branch (`git checkout -b feature/amazing-feature`)
3. Commit your changes (`git commit -m 'Add amazing feature'`)
4. Push to the branch (`git push origin feature/amazing-feature`)
5. Open a Pull Request

## License

This project is licensed under the MIT License - see the [LICENSE](LICENSE) file for details.

## Security

- Never commit credentials to version control
- Use environment variables or secure credential management
- Be cautious with database permissions - read-only access is sufficient
- Validate table name regex patterns to prevent ReDoS attacks

## Changelog

### 1.0.0 (2025-01-15)
- Initial release
- Command line interface
- Environment variable support
- Table filtering with regex
- Complete schema metadata export