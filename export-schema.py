#!/usr/bin/env python3
"""
MySQL Schema Export Utility

Exports MySQL/MariaDB database schema to JSON format with comprehensive metadata
including columns, constraints, indexes, foreign keys, and DDL statements.

@author Bill Nobes
@license MIT
@version 1.0.0
"""

import argparse
import configparser
import json
import os
import re
import sys
from datetime import datetime
from pathlib import Path

import pymysql.cursors


def show_help():
    """Display help information"""
    print("MySQL Schema Export Utility\n")
    print("Usage: python export-schema.py [OPTIONS]\n")
    print("Options:")
    print("  --host HOST          Database host (default: localhost)")
    print("  --database DB        Database name (required)")
    print("  --user USER          Database user (required)")
    print("  --password PASS      Database password")
    print("  --port PORT          Database port (default: 3306)")
    print("  --output DIR         Output directory (default: ./export)")
    print("  --filter REGEX       Table name filter regex (default: .* for all tables)")
    print("  --help, -h           Show this help message\n")
    print("Environment Variables:")
    print("  DB_HOST, DB_NAME, DB_USER, DB_PASS, DB_PORT")
    print("  OUTPUT_DIR, TABLE_NAME_REGEXP, SCHEMA_VERSION\n")
    print("Examples:")
    print("  python export-schema.py --database mydb --user root")
    print("  python export-schema.py --db mydb --user root --filter 'user_.*'")
    print("  DB_NAME=mydb DB_USER=root python export-schema.py")


def load_config_file(filename='config.ini'):
    """Load configuration from INI file"""
    if not os.path.exists(filename):
        return {}
    
    config = configparser.ConfigParser()
    try:
        config.read(filename)
        return config
    except Exception as e:
        print(f"Warning: Could not parse config file {filename}: {e}")
        return {}


def parse_args():
    """Parse command line arguments"""
    parser = argparse.ArgumentParser(description='MySQL Schema Export Utility', add_help=False)
    parser.add_argument('--help', '-h', action='store_true', help='Show help message')
    parser.add_argument('--host', help='Database host')
    parser.add_argument('--database', '--db', help='Database name')
    parser.add_argument('--user', help='Database user')
    parser.add_argument('--password', help='Database password')
    parser.add_argument('--port', type=int, help='Database port')
    parser.add_argument('--output', help='Output directory')
    parser.add_argument('--filter', help='Table name filter regex')
    
    return parser.parse_args()


def get_config_value(cli_arg, env_var, config_file, section, key, default=None):
    """Get configuration value with priority: CLI > env > config file > default"""
    if cli_arg is not None:
        return cli_arg
    
    env_val = os.getenv(env_var)
    if env_val is not None:
        return env_val
    
    if config_file and section in config_file and key in config_file[section]:
        return config_file[section][key]
    
    return default


def get_table_columns(connection, db_name, table_name):
    """Get column information for a table"""
    query = """
        SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, 
               EXTRA, COLUMN_COMMENT, ORDINAL_POSITION
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s
        ORDER BY ORDINAL_POSITION
    """
    
    with connection.cursor() as cursor:
        cursor.execute(query, (db_name, table_name))
        rows = cursor.fetchall()
        
        columns = []
        for row in rows:
            columns.append({
                'name': row['COLUMN_NAME'],
                'type': row['COLUMN_TYPE'],
                'nullable': row['IS_NULLABLE'] == 'YES',
                'default': row['COLUMN_DEFAULT'],
                'extra': row['EXTRA'],
                'comment': row['COLUMN_COMMENT'],
                'position': int(row['ORDINAL_POSITION'])
            })
        
        return columns


def get_table_constraints(connection, db_name, table_name):
    """Get constraint information for a table"""
    query = """
        SELECT tc.CONSTRAINT_NAME, tc.CONSTRAINT_TYPE, kcu.COLUMN_NAME, kcu.ORDINAL_POSITION
        FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS tc
        JOIN INFORMATION_SCHEMA.KEY_COLUMN_USAGE kcu
          ON tc.CONSTRAINT_NAME = kcu.CONSTRAINT_NAME
         AND tc.TABLE_SCHEMA = kcu.TABLE_SCHEMA
         AND tc.TABLE_NAME = kcu.TABLE_NAME
        WHERE tc.TABLE_SCHEMA = %s AND tc.TABLE_NAME = %s
        ORDER BY tc.CONSTRAINT_NAME, kcu.ORDINAL_POSITION
    """
    
    with connection.cursor() as cursor:
        cursor.execute(query, (db_name, table_name))
        rows = cursor.fetchall()
        
        primary_key = {}
        unique_constraints = {}
        
        for row in rows:
            if row['CONSTRAINT_TYPE'] == 'PRIMARY KEY':
                primary_key[int(row['ORDINAL_POSITION'])] = row['COLUMN_NAME']
            elif row['CONSTRAINT_TYPE'] == 'UNIQUE':
                constraint_name = row['CONSTRAINT_NAME']
                if constraint_name not in unique_constraints:
                    unique_constraints[constraint_name] = {}
                unique_constraints[constraint_name][int(row['ORDINAL_POSITION'])] = row['COLUMN_NAME']
        
        # Sort and flatten primary key
        sorted_pk = [primary_key[pos] for pos in sorted(primary_key.keys())]
        
        # Sort and flatten unique constraints
        unique_out = []
        for name, cols in unique_constraints.items():
            sorted_cols = [cols[pos] for pos in sorted(cols.keys())]
            unique_out.append({'name': name, 'columns': sorted_cols})
        
        return sorted_pk, unique_out


def get_table_indexes(connection, db_name, table_name):
    """Get index information for a table"""
    query = """
        SELECT INDEX_NAME, NON_UNIQUE, SEQ_IN_INDEX, COLUMN_NAME
        FROM INFORMATION_SCHEMA.STATISTICS
        WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s
        ORDER BY INDEX_NAME, SEQ_IN_INDEX
    """
    
    with connection.cursor() as cursor:
        cursor.execute(query, (db_name, table_name))
        rows = cursor.fetchall()
        
        index_buckets = {}
        
        for row in rows:
            index_name = row['INDEX_NAME']
            if index_name not in index_buckets:
                index_buckets[index_name] = {
                    'name': index_name,
                    'unique': row['NON_UNIQUE'] == 0,
                    'columns': {}
                }
            index_buckets[index_name]['columns'][int(row['SEQ_IN_INDEX'])] = row['COLUMN_NAME']
        
        # Skip PRIMARY index and flatten columns
        indexes = []
        for name, data in index_buckets.items():
            if name.upper() == 'PRIMARY':
                continue
            sorted_cols = [data['columns'][pos] for pos in sorted(data['columns'].keys())]
            indexes.append({
                'name': name,
                'unique': data['unique'],
                'columns': sorted_cols
            })
        
        return indexes


def get_table_foreign_keys(connection, db_name, table_name):
    """Get foreign key information for a table"""
    query = """
        SELECT kcu.CONSTRAINT_NAME, kcu.COLUMN_NAME,
               kcu.REFERENCED_TABLE_NAME AS ref_table,
               kcu.REFERENCED_COLUMN_NAME AS ref_column,
               rc.UPDATE_RULE, rc.DELETE_RULE
        FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE kcu
        JOIN INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS rc
          ON rc.CONSTRAINT_NAME = kcu.CONSTRAINT_NAME
         AND rc.CONSTRAINT_SCHEMA = kcu.TABLE_SCHEMA
        WHERE kcu.TABLE_SCHEMA = %s AND kcu.TABLE_NAME = %s
          AND kcu.REFERENCED_TABLE_NAME IS NOT NULL
    """
    
    with connection.cursor() as cursor:
        cursor.execute(query, (db_name, table_name))
        rows = cursor.fetchall()
        
        fk_buckets = {}
        
        for row in rows:
            constraint_name = row['CONSTRAINT_NAME']
            if constraint_name not in fk_buckets:
                fk_buckets[constraint_name] = {
                    'name': constraint_name,
                    'columns': [],
                    'ref_table': row['ref_table'],
                    'ref_columns': [],
                    'on_update': row['UPDATE_RULE'],
                    'on_delete': row['DELETE_RULE']
                }
            fk_buckets[constraint_name]['columns'].append(row['COLUMN_NAME'])
            fk_buckets[constraint_name]['ref_columns'].append(row['ref_column'])
        
        return list(fk_buckets.values())


def get_table_info(connection, db_name, table_name):
    """Get table metadata information"""
    query = """
        SELECT TABLE_ROWS, ENGINE, TABLE_COLLATION
        FROM INFORMATION_SCHEMA.TABLES
        WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s
    """
    
    with connection.cursor() as cursor:
        cursor.execute(query, (db_name, table_name))
        row = cursor.fetchone()
        
        if row:
            return {
                'row_count_est': int(row['TABLE_ROWS']) if row['TABLE_ROWS'] is not None else None,
                'engine': row['ENGINE'],
                'collation': row['TABLE_COLLATION']
            }
        
        return {'row_count_est': None, 'engine': None, 'collation': None}


def get_table_ddl(connection, table_name):
    """Get CREATE TABLE statement for a table"""
    query = f"SHOW CREATE TABLE `{table_name}`"
    
    with connection.cursor() as cursor:
        cursor.execute(query)
        row = cursor.fetchone()
        
        if row:
            # The result has keys 'Table' and 'Create Table'
            return row.get('Create Table', '')
        
        return ''


def main():
    """Main execution function"""
    # Parse arguments and load configuration
    args = parse_args()
    
    if args.help:
        show_help()
        sys.exit(0)
    
    config_file = load_config_file()
    
    # Get configuration values with priority: CLI > env > config file > defaults
    db_host = get_config_value(args.host, 'DB_HOST', config_file, 'database', 'host', 'localhost')
    db_name = get_config_value(args.database, 'DB_NAME', config_file, 'database', 'name', '')
    db_user = get_config_value(args.user, 'DB_USER', config_file, 'database', 'user', '')
    db_pass = get_config_value(args.password, 'DB_PASS', config_file, 'database', 'password', '')
    db_port = int(get_config_value(args.port, 'DB_PORT', config_file, 'database', 'port', 3306))
    
    table_filter = get_config_value(args.filter, 'TABLE_NAME_REGEXP', config_file, 'export', 'table_filter', '.*')
    output_dir = get_config_value(args.output, 'OUTPUT_DIR', config_file, 'export', 'output_dir', './export')
    schema_version = os.getenv('SCHEMA_VERSION', datetime.now().strftime('%Y-%m-%d'))
    
    # Convert PHP-style regex delimiters to Python regex if needed
    if table_filter.startswith('/') and table_filter.endswith('/'):
        table_filter = table_filter[1:-1]  # Remove PHP delimiters
    
    # Validate required configuration
    if not db_name or not db_user:
        print("Error: Database name and user are required.")
        print("Use --database and --user arguments, or set DB_NAME and DB_USER environment variables.")
        print("Run 'python export-schema.py --help' for usage information.")
        sys.exit(1)
    
    # Validate table filter regex
    try:
        re.compile(table_filter)
    except re.error as e:
        print(f"Error: Invalid regex pattern in table filter: {table_filter} - {e}")
        sys.exit(1)
    
    # Create output directory
    Path(output_dir).mkdir(parents=True, exist_ok=True)
    output_file = os.path.join(output_dir, f"schema_{db_name}.json")
    
    # Connect to database
    try:
        connection = pymysql.connect(
            host=db_host,
            port=db_port,
            user=db_user,
            password=db_pass,
            database=db_name,
            charset='utf8mb4',
            cursorclass=pymysql.cursors.DictCursor
        )
        print(f"Connected to database: {db_name}@{db_host}")
    except Exception as e:
        print(f"Connection failed: {e}")
        sys.exit(1)
    
    try:
        # Get all tables
        with connection.cursor() as cursor:
            cursor.execute("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")
            all_tables = [row[f'Tables_in_{db_name}'] for row in cursor.fetchall()]
        
        total_tables = len(all_tables)
        current_table = 0
        tables_out = []
        
        print(f"Processing {total_tables} tables with filter: {table_filter}...")
        
        # Filter and process tables
        pattern = re.compile(table_filter)
        
        for table in all_tables:
            # Skip tables that don't match the regex filter
            if not pattern.match(table):
                continue
            
            current_table += 1
            print(f"[{current_table}/{total_tables}] Processing table: {table}")
            
            # Get table components
            columns = get_table_columns(connection, db_name, table)
            primary_key, unique_constraints = get_table_constraints(connection, db_name, table)
            indexes = get_table_indexes(connection, db_name, table)
            foreign_keys = get_table_foreign_keys(connection, db_name, table)
            table_info = get_table_info(connection, db_name, table)
            ddl = get_table_ddl(connection, table)
            
            # Add to output
            tables_out.append({
                'name': table,
                'columns': columns,
                'primary_key': primary_key,
                'unique': unique_constraints,
                'indexes': indexes,
                'foreign_keys': foreign_keys,
                'table_info': table_info,
                'ddl': ddl
            })
        
        # Create output structure
        output = {
            'database': db_name,
            'generated_at': datetime.now().isoformat(),
            'schema_version': schema_version,
            'tables': tables_out
        }
        
        # Write JSON file
        try:
            with open(output_file, 'w', encoding='utf-8') as f:
                json.dump(output, f, indent=2, ensure_ascii=False)
            
            filtered_count = len(tables_out)
            file_size = os.path.getsize(output_file) / 1024
            print(f"Export complete: {output_file}")
            print(f"Exported {filtered_count} tables, {file_size:.1f} KB")
            
        except Exception as e:
            print(f"Error: Failed to write output file {output_file}: {e}")
            sys.exit(1)
    
    finally:
        connection.close()


if __name__ == '__main__':
    main()